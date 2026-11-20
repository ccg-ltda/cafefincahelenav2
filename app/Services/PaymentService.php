<?php

namespace App\Services;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happens to an order when its payment is approved. Called by the Wompi webhook once it is integrated (and by the tests):
 * there is NO route, button or endpoint in the admin that marks an order as paid or moves it to "confirmed".
 */
class PaymentService
{
    public function __construct(private readonly OrderStatusService $statuses) {}

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
