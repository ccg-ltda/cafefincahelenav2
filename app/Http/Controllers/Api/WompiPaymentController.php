<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OrderNotPayableException;
use App\Exceptions\WompiRedirectUrlException;
use App\Exceptions\WompiUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\PaymentService;
use App\Services\WompiClient;
use App\Services\WompiService;
use App\Support\ActivityRedactor;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The three public doors of the Wompi integration. Wompi is the source of truth: nothing here trusts what the browser says
 * (the result page only passes a transaction id, the server asks Wompi), and every answer goes through
 * PaymentService::applyTransaction, which checks reference, amount and currency and is idempotent.
 */
class WompiPaymentController extends Controller
{
    private const GENERIC = 'No pudimos iniciar el pago. Revisa los datos e inténtalo de nuevo.';

    public function __construct(private readonly WompiService $wompi, private readonly WompiClient $client, private readonly PaymentService $payments) {}

    /**
     * Creates (or reuses) a payment attempt and answers the Web Checkout URL. The caller must prove they own the order: the
     * reference AND the email must match, or the transaction id of one of its attempts (the "Intentar de nuevo" button).
     * Whatever does not match gets the same generic answer, so nobody can find out whether an order exists.
     */
    public function attempt(Request $request): JsonResponse
    {
        if (! $this->wompi->enabled()) {
            return response()->json(['message' => 'Los pagos en línea no están disponibles en este momento.'], 503);
        }
        try {
            $this->wompi->redirectUrl(); // refused BEFORE creating the attempt: a local APP_URL makes Wompi answer 403
        } catch (WompiRedirectUrlException $error) {
            Log::warning('Wompi: APP_URL es una dirección local, el pago no se inicia', ['host' => parse_url((string) config('app.url'), PHP_URL_HOST)]);

            return response()->json(['message' => $error->getMessage(), 'code' => WompiRedirectUrlException::CODE], 503);
        }
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:40', 'required_without:transaction_id'],
            'customer_email' => ['nullable', 'string', 'max:255', 'required_with:reference'],
            'transaction_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $order = null;
        if (filled($data['transaction_id'] ?? null)) {
            try {
                $payment = $this->paymentFromTransaction($data['transaction_id']);
            } catch (WompiUnavailableException) {
                return response()->json(['message' => 'No pudimos consultar tu pago ahora. Inténtalo en un momento.'], 503);
            }
            $order = $payment?->order;
        } else {
            $order = Order::query()->where('reference', $data['reference'])->first();
            if ($order && ($order->customer_email === null || mb_strtolower($order->customer_email) !== mb_strtolower(trim($data['customer_email'])))) {
                $order = null;
            }
        }
        if (! $order) {
            return response()->json(['message' => self::GENERIC], 422);
        }

        try {
            $payment = $this->payments->startAttempt($order);
        } catch (OrderNotPayableException $error) {
            return response()->json(['message' => $error->getMessage(), 'paid' => $error->alreadyPaid], 409);
        }

        return response()->json(['checkout_url' => $this->wompi->checkoutUrl($payment, $order)])->header('Cache-Control', 'no-store');
    }

    /**
     * The result page asks here with the transaction id Wompi appended to the redirect. The server asks Wompi, validates it
     * against our attempt and answers only what the customer needs: no address, no phone, no complete email, nothing of
     * any other order. An id that is not one of our attempts answers "unknown" without saying why.
     */
    public function result(Request $request): JsonResponse
    {
        $id = (string) $request->query('id');
        if (! $this->wompi->enabled() || ! preg_match('/^[A-Za-z0-9._-]{3,80}$/', $id)) {
            return $this->unknown();
        }

        try {
            $transaction = $this->client->transaction($id);
            $payment = $transaction ? Payment::query()->where('provider', WompiService::PROVIDER)->where('reference', $transaction['reference'] ?? '')->first() : null;
            if (! $payment) {
                return $this->unknown();
            }
            $this->payments->applyTransaction($payment, $transaction);
        } catch (WompiUnavailableException) {
            return response()->json(['state' => 'unavailable', 'message' => 'No pudimos consultar tu pago ahora. Inténtalo en un momento.'], 503)->header('Cache-Control', 'no-store');
        }

        $payment->refresh();
        $order = $payment->order()->with('items')->first();
        $state = $order->payment_status === 'paid' || $payment->status === 'approved' ? 'approved' : ($payment->status === 'pending' ? 'pending' : 'declined');

        return response()->json(['state' => $state, 'message' => $state === 'declined' ? $payment->status_message : null, 'method' => $payment->payment_method_type,
            'can_retry' => $state === 'declined' && $order->status === 'pending' && $order->payment_status !== 'paid',
            'order' => [
                'reference' => $order->reference, 'total_cop' => $order->total_cop, 'currency' => $order->currency,
                'email' => ActivityRedactor::maskEmail($order->customer_email), // a***@dominio.com (null when the order has none)
                'items' => $order->items->map(fn ($item) => ['name' => $item->name, 'quantity' => $item->quantity])->values(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Wompi's webhook (transaction.updated). Source of truth even if the customer never comes back. An invalid checksum is a 401
     * (logged without data); a valid one is always answered 200 so Wompi stops retrying, even when it changed nothing.
     */
    public function events(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! $this->wompi->enabled() || ! $this->wompi->validEvent($payload, $request->header('X-Event-Checksum'))) {
            Log::warning('Wompi: evento rechazado por checksum inválido');

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $transaction = data_get($payload, 'data.transaction');
        $event = PaymentEvent::query()->newModelInstance([
            'provider' => WompiService::PROVIDER, 'checksum' => strtolower((string) data_get($payload, 'signature.checksum')), 'event' => mb_substr((string) ($payload['event'] ?? ''), 0, 60),
            'external_transaction_id' => data_get($transaction, 'id'), 'reference' => data_get($transaction, 'reference'), 'status' => data_get($transaction, 'status'),
            'amount_in_cents' => is_numeric(data_get($transaction, 'amount_in_cents')) ? (int) data_get($transaction, 'amount_in_cents') : null, 'outcome' => 'received',
        ]);
        try {
            $event->save(); // unique (provider, checksum): the same event twice stops here
        } catch (QueryException $error) {
            if ((string) $error->getCode() === '23000') {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            throw $error;
        }

        $outcome = 'ignored';
        if (($payload['event'] ?? null) === 'transaction.updated' && is_array($transaction)) {
            $environment = $this->wompi->environment() === 'production' ? 'prod' : 'test';
            $payment = Payment::query()->where('provider', WompiService::PROVIDER)->where('reference', $transaction['reference'] ?? '')->first();
            if (($payload['environment'] ?? $environment) !== $environment) {
                $outcome = 'ignored';
            } elseif (! $payment) {
                $outcome = 'unknown_reference';
            } else {
                $outcome = $this->payments->applyTransaction($payment, $transaction);
            }
        }
        $event->forceFill(['outcome' => $outcome])->save();

        return response()->json(['received' => true]);
    }

    /** The attempt a transaction id belongs to (asked to Wompi, never trusted from the request). */
    private function paymentFromTransaction(string $id): ?Payment
    {
        $transaction = $this->client->transaction($id);

        return $transaction ? Payment::query()->where('provider', WompiService::PROVIDER)->where('reference', $transaction['reference'] ?? '')->first() : null;
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['state' => 'unknown', 'message' => 'No encontramos ese pago.'], 404)->header('Cache-Control', 'no-store');
    }
}
