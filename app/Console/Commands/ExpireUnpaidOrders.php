<?php

namespace App\Console\Commands;

use App\Exceptions\WompiUnavailableException;
use App\Models\Order;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\WompiClient;
use App\Services\WompiService;
use Illuminate\Console\Command;

/**
 * Cancels the orders that nobody paid. An order is considered only after WOMPI_PENDING_EXPIRY_MINUTES, and BEFORE cancelling it
 * Wompi is asked about every attempt: an approved one marks the order paid instead, and an attempt still PENDING in Wompi (PSE,
 * Nequi, …) keeps the order alive until it resolves, with a 24 h ceiling. If Wompi cannot be asked, the order is left alone.
 *
 * The deadline is per order: one with `payment_due_at` (a manual order: WOMPI_MANUAL_PENDING_EXPIRY_HOURS after its last link) expires
 * at that instant; the others (the storefront) after WOMPI_PENDING_EXPIRY_MINUTES from their creation, exactly as before.
 *
 * Which orders: every pending order that has a Wompi attempt; and, when payment is required before fulfilment, every pending
 * order (the customer may have left before reaching the payment). Orders of the manual era (no attempt, payment not required)
 * are never touched. Cancelling is OrderStatusService::cancel (stock back, coupon released, history, idempotent).
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Cancela los pedidos sin pago vencidos (consulta a Wompi antes de cancelar)';

    private const CEILING_HOURS = 24;

    public function handle(WompiService $wompi, WompiClient $client, PaymentService $payments, OrderStatusService $statuses): int
    {
        if (! $wompi->enabled()) {
            $this->warn('Wompi no está configurado: no se cancela ningún pedido (no se puede comprobar si hubo pago).');

            return self::SUCCESS;
        }

        $minutes = max(1, (int) config('services.wompi.pending_expiry_minutes', 60));
        $requirePayment = (bool) config('shop.require_payment_before_fulfillment');
        $orders = Order::query()->where('status', 'pending')->where('payment_status', 'pending')
            ->where(fn ($due) => $due->where('payment_due_at', '<=', now())
                ->orWhere(fn ($legacy) => $legacy->whereNull('payment_due_at')->where('created_at', '<=', now()->subMinutes($minutes))))
            ->when(! $requirePayment, fn ($query) => $query->whereHas('payments', fn ($payments) => $payments->where('provider', WompiService::PROVIDER)))
            ->orderBy('id')->get();

        $cancelled = $paid = $waiting = $skipped = 0;
        foreach ($orders as $order) {
            try {
                $payments->refreshFromWompi($order, $client);
            } catch (WompiUnavailableException) {
                $skipped++; // could not ask: never cancel on a guess

                continue;
            }

            $order->refresh();
            if ($order->status !== 'pending') {
                $order->payment_status === 'paid' ? $paid++ : $skipped++;

                continue;
            }
            $inFlight = $order->payments()->where('provider', WompiService::PROVIDER)->where('status', 'pending')->whereNotNull('external_transaction_id')->exists();
            // The ceiling for a payment still PENDING in Wompi counts from the deadline (from the creation when the order has none).
            if ($inFlight && ($order->payment_due_at ?? $order->created_at)->copy()->addHours(self::CEILING_HOURS)->isFuture()) {
                $waiting++;

                continue;
            }
            $statuses->cancel($order, $order->payment_due_at ? 'Pedido sin pago: venció el plazo de pago.' : "Pedido sin pago: venció a los {$minutes} minutos.");
            $cancelled++;
        }

        $this->info("Vencidos revisados: {$orders->count()} · cancelados: {$cancelled} · pagados al consultar: {$paid} · esperando pago pendiente: {$waiting} · sin poder consultar: {$skipped}");

        return self::SUCCESS;
    }
}
