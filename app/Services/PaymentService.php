<?php

namespace App\Services;

use App\Events\OrderPaid;
use App\Events\PaymentReceivedOnCancelledOrder;
use App\Exceptions\OrderNotPayableException;
use App\Exceptions\PaymentLinkException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place that changes `orders.payment_status` and `payments`. What happens to an order when a payment is approved is
 * driven by Wompi's answer (the webhook, the result page, the expiry command or the admin "Consultar estado"), never by a
 * button: there is NO route in the admin that marks an order as paid or moves it to "confirmed".
 *
 * Every Wompi answer goes through applyTransaction(), which checks reference, amount and currency against OUR attempt before
 * touching anything, and is idempotent (the same answer twice changes nothing).
 */
class PaymentService
{
    public function __construct(private readonly OrderStatusService $statuses, private readonly WompiService $wompi, private readonly ActivityLogger $activity) {}

    /**
     * A payment attempt for the order: reuses the one still open (the customer pressed the button twice) or creates the next one
     * (`CFH-…-1`, `-2`, …: Wompi needs a new reference per transaction). It never creates an order and never touches stock.
     */
    public function startAttempt(Order $order): Payment
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_status === 'paid') {
                throw new OrderNotPayableException(true);
            }
            if ($locked->status !== 'pending') {
                throw new OrderNotPayableException(false);
            }

            $attempts = Payment::query()->where('order_id', $locked->id)->where('provider', WompiService::PROVIDER)->orderBy('id')->get();
            $open = $attempts->last(fn (Payment $payment) => $payment->status === 'pending' && $payment->external_transaction_id === null && $payment->checkout_expires_at?->isFuture());
            if ($open) {
                return $open;
            }

            // A manual order's link lasts WOMPI_MANUAL_PENDING_EXPIRY_HOURS and is also the instant the unpaid order is cancelled:
            // the deadline always equals the link that was last given to the customer.
            $manual = $locked->channel === 'manual';
            $expires = $manual ? now()->addHours((int) config('services.wompi.manual_pending_expiry_hours', 24)) : now()->addMinutes((int) config('services.wompi.checkout_window_minutes', 30));
            if ($manual) {
                $locked->forceFill(['payment_due_at' => $expires])->save();
            }

            $payment = new Payment;
            $payment->forceFill([
                'order_id' => $locked->id, 'provider' => WompiService::PROVIDER, 'reference' => $locked->reference.'-'.($attempts->count() + 1),
                'status' => 'pending', 'amount_cop' => $locked->total_cop, 'currency' => $locked->currency,
                'checkout_expires_at' => $expires,
            ])->save();

            return $payment;
        });
    }

    /** The attempt whose link can still be paid (not expired, no transaction started on it), or null. */
    public function openAttempt(Order $order): ?Payment
    {
        return Payment::query()->where('order_id', $order->id)->where('provider', WompiService::PROVIDER)->where('status', 'pending')
            ->whereNull('external_transaction_id')->where('checkout_expires_at', '>', now())->orderByDesc('id')->first();
    }

    /**
     * "Generar nuevo link de pago" of a manual pending order. Only when the current link has expired: a Wompi link cannot be
     * cancelled, so two valid links could be paid twice. It is a new attempt of the SAME order (and extends its payment deadline).
     *
     * @throws PaymentLinkException when the order is not manual, a link is still valid or the customer is already paying
     * @throws OrderNotPayableException when the order is paid or no longer pending
     */
    public function renewManualLink(Order $order, User $by): Payment
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->channel !== 'manual') {
                throw new PaymentLinkException('Solo los pedidos manuales tienen un link de pago generado por el equipo.', 422);
            }
            if ($locked->payment_status === 'paid') {
                throw new OrderNotPayableException(true);
            }
            if ($locked->status !== 'pending') {
                throw new OrderNotPayableException(false);
            }
            if ($this->openAttempt($locked)) {
                throw new PaymentLinkException('El link de pago vigente sigue activo: cópialo en lugar de generar otro (con dos links vigentes el cliente podría pagar dos veces).', 409);
            }
            if (Payment::query()->where('order_id', $locked->id)->where('provider', WompiService::PROVIDER)->where('status', 'pending')->whereNotNull('external_transaction_id')->exists()) {
                throw new PaymentLinkException('El cliente ya está pagando este pedido en Wompi. Espera a que se resuelva.', 409);
            }

            $payment = $this->startAttempt($locked);
            $this->activity->log('orders', 'payment_link_created', $locked, [], ['attempt' => $payment->reference, 'expires' => $payment->checkout_expires_at?->toIso8601String()], 'Nuevo link de pago', $by);

            return $payment;
        });
    }

    /**
     * Applies what Wompi says about a transaction to our attempt. Returns what happened:
     * `applied` | `duplicate` (nothing new) | `mismatch` (reference, amount or currency differ: nothing is changed) | `ignored` (unknown status).
     * An approved payment moves a pending order to "Pagado" (markPaid); on a cancelled order it is recorded and raises the alert.
     *
     * @param  array<string, mixed>  $transaction  the `transaction` of an event or the `data` of GET /transactions/{id}
     */
    public function applyTransaction(Payment $payment, array $transaction): string
    {
        $status = $this->wompi->mapStatus($transaction['status'] ?? null);
        if ($status === null) {
            return 'ignored';
        }
        if (($transaction['reference'] ?? null) !== $payment->reference
            || (int) ($transaction['amount_in_cents'] ?? -1) !== (int) $payment->amount_cop * 100
            || ($transaction['currency'] ?? $payment->currency) !== $payment->currency) {
            Log::warning('Wompi: la transacción no coincide con el intento', ['payment_id' => $payment->id]); // ids only: no customer data, no secrets

            return 'mismatch';
        }

        return DB::transaction(function () use ($payment, $transaction, $status) {
            Order::query()->lockForUpdate()->findOrFail($payment->order_id); // order first, then payment: same order of locks everywhere
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = Order::query()->findOrFail($locked->order_id);

            if ($locked->status === 'approved') {
                return 'duplicate'; // an approved payment never goes back
            }
            if ($locked->status !== 'pending' && $status === 'pending') {
                return 'duplicate'; // a final answer is not undone by a late "pending"
            }
            if ($locked->status === $status && ($transaction['id'] ?? null) === $locked->external_transaction_id) {
                return 'duplicate';
            }

            $locked->forceFill([
                'external_transaction_id' => $locked->external_transaction_id ?? ($transaction['id'] ?? null),
                'status' => $status,
                'payment_method_type' => isset($transaction['payment_method_type']) ? mb_substr((string) $transaction['payment_method_type'], 0, 40) : $locked->payment_method_type,
                'status_message' => isset($transaction['status_message']) ? mb_substr((string) $transaction['status_message'], 0, 255) : $locked->status_message,
            ]);
            if ($status === 'approved') {
                $locked->forceFill(['confirmed_at' => now(), 'verified_at' => now()]); // verified server side against Wompi
            }
            $locked->save();

            if ($status === 'approved') {
                if ($order->status === 'cancelled') {
                    $this->flagPaymentOnCancelledOrder($locked, $order);
                } else {
                    $this->markPaid($order);
                }
            }

            return 'applied';
        });
    }

    /** An approved payment arrived for an order that was already cancelled: the payment is kept, the order stays cancelled, an admin must act. */
    private function flagPaymentOnCancelledOrder(Payment $payment, Order $order): void
    {
        if ($payment->review_required_at !== null) {
            return;
        }
        $payment->forceFill(['review_required_at' => now()])->save();
        $this->activity->log('orders', 'payment_received_on_cancelled', $order, [], ['reference' => $payment->reference, 'amount_cop' => $payment->amount_cop], null, null, 'Sistema');
        DB::afterCommit(fn () => PaymentReceivedOnCancelledOrder::dispatch($order, $payment));
    }

    /**
     * Refreshes every Wompi attempt of the order from the API (by transaction id, or by our reference when the customer never came back)
     * and applies the answers with the same rules as the webhook. Throws WompiUnavailableException when Wompi cannot be asked.
     */
    public function refreshFromWompi(Order $order, WompiClient $client): void
    {
        foreach ($order->payments()->where('provider', WompiService::PROVIDER)->orderBy('id')->get() as $attempt) {
            /** @var Payment $attempt */
            $transaction = $attempt->external_transaction_id
                ? $client->transaction($attempt->external_transaction_id)
                : ($client->transactionsByReference($attempt->reference)[0] ?? null);
            if ($transaction) {
                $this->applyTransaction($attempt, $transaction);
            }
        }
    }

    /** An administrator marks the alert of this order as reviewed. Who and when stay in the payment and in the Historial. */
    public function markReviewed(Order $order, User $by): int
    {
        return DB::transaction(function () use ($order, $by) {
            $payments = Payment::query()->where('order_id', $order->id)->whereNotNull('review_required_at')->whereNull('reviewed_at')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $payment->forceFill(['reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
            }
            if ($payments->isNotEmpty()) {
                $this->activity->log('orders', 'payment_reviewed', $order, [], ['reference' => $order->reference, 'payments' => $payments->count()], null, $by);
            }

            return $payments->count();
        });
    }

    /** Approved payments on cancelled orders that nobody has reviewed yet (the alert of Hoy and the Dashboard). */
    public function pendingReviews(): Collection
    {
        return Payment::query()->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereNotNull('payments.review_required_at')->whereNull('payments.reviewed_at')
            ->orderBy('payments.review_required_at')
            ->get(['orders.id as order_id', 'orders.reference as order_reference', 'payments.amount_cop', 'payments.review_required_at'])
            ->map(fn ($row) => ['order_id' => (int) $row->order_id, 'reference' => $row->order_reference, 'amount_cop' => (int) $row->amount_cop, 'at' => \Illuminate\Support\Carbon::parse($row->review_required_at)->toIso8601String()])
            ->values();
    }

    /** Idempotent: an order already paid is returned untouched, without events. */
    public function markPaid(Order $order, ?User $by = null): Order
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_status === 'paid') {
                return $locked;
            }
            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['Un pedido cancelado no se puede marcar como pagado.']]);
            }

            $locked->forceFill(['payment_status' => 'paid', 'paid_at' => now()])->save();
            // An order that was already being worked (payment not required yet) keeps its stage: only a pending one becomes "Pagado".
            $paid = $locked->status === 'pending' ? $this->statuses->confirmPayment($locked, $by) : $locked;
            DB::afterCommit(fn () => OrderPaid::dispatch($paid));

            return $paid;
        });
    }
}
