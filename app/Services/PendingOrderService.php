<?php

namespace App\Services;

use App\Exceptions\WompiUnavailableException;
use App\Models\Order;

/**
 * A customer who left for Wompi and came back to the store: what is the state of their pending order, and may they cancel it.
 * The caller proves the order is theirs with reference + email (anything else is "unknown", the same answer for an order that does
 * not exist, so nobody can probe references). Cancelling is OrderStatusService::cancel (stock and coupon back once, history, Historial
 * with actor "Cliente"); it is refused when Wompi says the payment is approved or still in process, and never on a guess.
 */
class PendingOrderService
{
    public const REFERENCE_PATTERN = '/^CFH-[A-Z0-9]{12}$/';

    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly PaymentService $payments,
        private readonly WompiClient $client,
        private readonly WompiService $wompi,
    ) {}

    public function find(?string $reference, ?string $email): ?Order
    {
        $reference = strtoupper(trim((string) $reference));
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! preg_match(self::REFERENCE_PATTERN, $reference)) {
            return null;
        }
        $order = Order::query()->where('reference', $reference)->first();

        return $order && hash_equals(strtolower(trim((string) $order->customer_email)), $email) ? $order : null;
    }

    /**
     * pending | paid | cancelled, with what the notice needs. A pending order with Wompi attempts is refreshed from Wompi first, so a
     * payment whose webhook is late is seen as paid; if Wompi cannot be asked the stored state is answered.
     *
     * @return array{state: string, reference: string, total_cop: int, can_cancel: bool}
     */
    public function state(Order $order): array
    {
        if ($order->status === 'pending' && $this->wompi->enabled()) {
            try {
                $this->payments->refreshFromWompi($order, $this->client);
            } catch (WompiUnavailableException) {
                // Not asking again: the stored state is the answer.
            }
            $order->refresh();
        }

        $state = $order->status === 'cancelled' ? 'cancelled' : ($order->status === 'pending' && $order->payment_status !== 'paid' ? 'pending' : 'paid');

        return ['state' => $state, 'reference' => $order->reference, 'total_cop' => (int) $order->total_cop, 'can_cancel' => $state === 'pending' && ! $this->paymentInFlight($order)];
    }

    /**
     * The customer cancels their own pending order. Returns `cancelled` (also when it already was: idempotent), `paid` (it was paid,
     * nothing is cancelled), `in_flight` (Wompi has a payment in process) or `unavailable` (Wompi could not be asked).
     */
    public function cancel(Order $order): string
    {
        if ($order->status === 'cancelled') {
            return 'cancelled';
        }
        if ($order->status !== 'pending' || $order->payment_status === 'paid') {
            return 'paid';
        }
        if ($this->wompi->enabled()) {
            try {
                $this->payments->refreshFromWompi($order, $this->client);
            } catch (WompiUnavailableException) {
                return 'unavailable'; // never cancel without being able to check for a payment
            }
            $order->refresh();
            if ($order->status !== 'pending' || $order->payment_status === 'paid') {
                return $order->status === 'cancelled' ? 'cancelled' : 'paid';
            }
        }
        if ($this->paymentInFlight($order)) {
            return 'in_flight';
        }
        $this->statuses->cancel($order, 'Cancelado por el cliente desde la tienda.', null, 'Cliente');

        return 'cancelled';
    }

    /** A Wompi transaction exists for the order and is still PENDING (PSE, Nequi…): it may turn into money at any moment. */
    private function paymentInFlight(Order $order): bool
    {
        return $order->payments()->where('provider', WompiService::PROVIDER)->where('status', 'pending')->whereNotNull('external_transaction_id')->exists();
    }
}
