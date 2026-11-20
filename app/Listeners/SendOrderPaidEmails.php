<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\OrderNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Receipt to the customer and "new paid order" to the administrators. Never throws: an email problem must not undo or block the payment. */
class SendOrderPaidEmails
{
    public function __construct(private readonly OrderNotificationService $notifications) {}

    public function handle(OrderPaid $event): void
    {
        try {
            $this->notifications->forPaid($event->order);
        } catch (Throwable $e) {
            Log::error('No se pudo preparar el correo del pedido.', ['order_id' => $event->order->id, 'listener' => self::class, 'error' => $this->notifications->scrub($e->getMessage())]);
        }
    }
}
