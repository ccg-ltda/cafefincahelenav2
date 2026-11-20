<?php

namespace App\Listeners;

use App\Events\PaymentReceivedOnCancelledOrder;
use App\Services\OrderNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Alerts the administrators about an approved payment on a cancelled order. Never throws: an email problem must not undo or block the payment. */
class SendPaymentOnCancelledAlert
{
    public function __construct(private readonly OrderNotificationService $notifications) {}

    public function handle(PaymentReceivedOnCancelledOrder $event): void
    {
        try {
            $this->notifications->forPaymentOnCancelled($event->order);
        } catch (Throwable $e) {
            Log::error('No se pudo preparar el correo del pedido.', ['order_id' => $event->order->id, 'listener' => self::class, 'error' => $this->notifications->scrub($e->getMessage())]);
        }
    }
}
