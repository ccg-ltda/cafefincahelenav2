<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Services\OrderNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Tells the customer about preparing, ready, shipped, delivered and (if it was paid) cancelled. Never throws: an email problem must not undo or block the status change. */
class SendOrderStatusEmail
{
    public function __construct(private readonly OrderNotificationService $notifications) {}

    public function handle(OrderStatusChanged $event): void
    {
        try {
            $this->notifications->forStatus($event->order, $event->to);
        } catch (Throwable $e) {
            Log::error('No se pudo preparar el correo del pedido.', ['order_id' => $event->order->id, 'listener' => self::class, 'error' => $this->notifications->scrub($e->getMessage())]);
        }
    }
}
