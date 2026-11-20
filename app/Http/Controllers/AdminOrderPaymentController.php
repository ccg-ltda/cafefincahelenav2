<?php

namespace App\Http\Controllers;

use App\Exceptions\WompiUnavailableException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\WompiClient;
use App\Services\WompiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of the payment of an order. Neither endpoint can mark anything as paid by hand: "refresh" only asks Wompi and lets
 * PaymentService apply the answer (same rules as the webhook), "review" only clears the alert of an approved payment that landed
 * on a cancelled order. Both are admin only (payments.refresh / payments.review).
 */
class AdminOrderPaymentController extends Controller
{
    public function __construct(private readonly WompiService $wompi, private readonly WompiClient $client, private readonly PaymentService $payments) {}

    /** "Consultar estado en Wompi": refreshes every attempt of the order from the API. */
    public function refresh(Request $request, int $order): JsonResponse
    {
        $found = Order::query()->findOrFail($order);
        if (! $this->wompi->enabled()) {
            return response()->json(['message' => 'Wompi no está configurado en este servidor.'], 422);
        }

        $outcomes = [];
        try {
            foreach ($found->payments()->where('provider', WompiService::PROVIDER)->orderBy('id')->get() as $attempt) {
                /** @var Payment $attempt */
                $transaction = $attempt->external_transaction_id
                    ? $this->client->transaction($attempt->external_transaction_id)
                    : ($this->client->transactionsByReference($attempt->reference)[0] ?? null);
                $outcomes[$attempt->reference] = $transaction ? $this->payments->applyTransaction($attempt, $transaction) : 'not_found';
            }
        } catch (WompiUnavailableException) {
            return response()->json(['message' => 'No se pudo consultar a Wompi ahora. Inténtalo de nuevo en un momento.'], 503);
        }

        return response()->json(['outcomes' => $outcomes])->header('Cache-Control', 'private, no-store');
    }

    /** "Revisado": the alert of this order goes away; who and when stay in the payment and in the Historial. */
    public function review(Request $request, int $order): JsonResponse
    {
        $count = $this->payments->markReviewed(Order::query()->findOrFail($order), $request->user());

        return response()->json(['reviewed' => $count])->header('Cache-Control', 'private, no-store');
    }
}
