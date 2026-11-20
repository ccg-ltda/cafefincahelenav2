<?php

namespace App\Services;

use App\Jobs\SendOrderNotification;
use App\Mail\OrderNotificationMail;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\User;
use App\Support\ActivityRedactor;
use App\Support\OrderEmailData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The ONLY place that decides which transactional emails an order gets, queues them and sends them. Listeners call the `for*` methods
 * (they never throw: an email must not break a payment or a status change); the queued job calls deliver().
 *
 * The row in order_notifications (unique per order + type + recipient) is the idempotency key: the same webhook or status twice finds
 * the row already there and queues nothing. Customers never get a mail for a pending order or for an unpaid one that expires.
 */
class OrderNotificationService
{
    /** Order status -> email type for the customer (confirmed has none: OrderPaid already sent the receipt). */
    private const STATUS_TYPES = [
        'preparing' => 'customer_preparing', 'ready' => 'customer_ready', 'shipped' => 'customer_shipped',
        'delivered' => 'customer_delivered', 'cancelled' => 'customer_cancelled',
    ];

    public function forPaid(Order $order): void
    {
        $this->queueToCustomer($order, 'customer_paid');
        $this->queueToAdmins($order, 'admin_new_paid');
    }

    public function forStatus(Order $order, string $to): void
    {
        $type = self::STATUS_TYPES[$to] ?? null;
        if (! $type) {
            return;
        }
        // A cancelled order only tells the customer when money was received (and that a refund is coming).
        if ($to === 'cancelled' && $order->payment_status !== 'paid') {
            return;
        }
        $this->queueToCustomer($order, $type);
    }

    public function forPaymentOnCancelled(Order $order): void
    {
        $this->queueToAdmins($order, 'admin_payment_on_cancelled');
    }

    /** @return list<string> */
    public function adminRecipients(): array
    {
        return array_values((array) config('shop.admin_notification_emails', []));
    }

    private function queueToCustomer(Order $order, string $type): void
    {
        $email = trim((string) $order->customer_email);
        if ($email === '' && $order->channel === 'manual') {
            return; // a manual order whose customer gave only a phone: there is nobody to e-mail
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Correo de pedido no enviado: el pedido no tiene un correo válido.', ['order_id' => $order->id, 'type' => $type]);

            return;
        }
        $this->queue($order, $type, strtolower($email));
    }

    private function queueToAdmins(Order $order, string $type): void
    {
        $recipients = $this->adminRecipients();
        if ($recipients === []) {
            Log::warning('Correo al administrador no enviado: SHOP_ADMIN_NOTIFICATION_EMAILS está vacía.', ['order_id' => $order->id, 'type' => $type]);

            return;
        }
        foreach ($recipients as $recipient) {
            $this->queue($order, $type, $recipient);
        }
    }

    /** Registers the email and queues its sending, ONCE: an existing row (same order, type, recipient) means it already went or is on its way. */
    public function queue(Order $order, string $type, string $recipient): void
    {
        $now = now();
        $created = OrderNotification::query()->insertOrIgnore([
            'order_id' => $order->id, 'type' => $type, 'recipient' => $recipient, 'status' => 'queued', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($created === 0) {
            return;
        }
        $row = OrderNotification::query()->where(['order_id' => $order->id, 'type' => $type, 'recipient' => $recipient])->first();
        if ($row) {
            SendOrderNotification::dispatch($row->id);
        }
    }

    /** What the queued job does. Any failure is stored (summarised, addresses masked) and rethrown so the queue retries it. */
    public function deliver(int $notificationId, bool $force = false): void
    {
        $notification = OrderNotification::query()->find($notificationId);
        if (! $notification || ($notification->status === 'sent' && ! $force)) {
            return;
        }
        $order = Order::query()->with(['items.variant', 'assignedDelivery'])->find($notification->order_id);
        if (! $order) {
            return;
        }

        $notification->forceFill(['attempts' => $notification->attempts + 1])->save();
        try {
            Mail::to($notification->recipient)->send(new OrderNotificationMail($order, $notification->type));
        } catch (Throwable $e) {
            $notification->forceFill(['error' => $this->scrub($e->getMessage())])->save();
            Log::warning('Correo del pedido no enviado, se reintentará.', ['order_id' => $order->id, 'type' => $notification->type, 'attempt' => $notification->attempts, 'error' => $this->scrub($e->getMessage())]);

            throw $e;
        }
        $notification->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
    }

    /** The queue gave up (all attempts used): the row says so and an administrator can resend it from the order. */
    public function markFailed(int $notificationId, Throwable $e): void
    {
        $notification = OrderNotification::query()->find($notificationId);
        if (! $notification) {
            return;
        }
        $notification->forceFill(['status' => 'failed', 'error' => $this->scrub($e->getMessage())])->save();
        Log::error('Correo del pedido no enviado: se agotaron los intentos.', ['order_id' => $notification->order_id, 'type' => $notification->type, 'error' => $this->scrub($e->getMessage())]);
    }

    /** An administrator sends it again (current order data, same type). Recorded in the Historial; refused while it is still in the queue. */
    public function resend(OrderNotification $notification, User $by): OrderNotification
    {
        return DB::transaction(function () use ($notification, $by) {
            $locked = OrderNotification::query()->lockForUpdate()->findOrFail($notification->id);
            if ($locked->status === 'queued') {
                throw ValidationException::withMessages(['notification' => ['Este correo ya está en cola: espera a que se envíe o falle.']]);
            }
            $locked->forceFill(['status' => 'queued', 'error' => null])->save();
            $order = Order::query()->findOrFail($locked->order_id);
            app(ActivityLogger::class)->log('orders', 'email_resent', $order, [], ['type' => $locked->type, 'recipient_masked' => ActivityRedactor::maskEmail($locked->recipient)], actor: $by);
            DB::afterCommit(fn () => SendOrderNotification::dispatch($locked->id, true));

            return $locked;
        });
    }

    /** The "Correos" section of the order (admin only): what exists, with the address masked. */
    public function listFor(Order $order): array
    {
        return OrderNotification::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn (OrderNotification $row) => [
            'id' => $row->id, 'type' => $row->type, 'label' => OrderEmailData::TYPES[$row->type]['label'] ?? $row->type,
            'audience' => OrderEmailData::TYPES[$row->type]['audience'] ?? 'customer',
            'recipient' => ActivityRedactor::maskEmail($row->recipient) ?? '—', 'status' => $row->status, 'attempts' => $row->attempts,
            'sent_at' => $row->sent_at?->toIso8601String(), 'error' => $row->error,
        ])->values()->all();
    }

    /** Short text for the row and the log: any address in it is masked and it is cut to 200 characters. */
    public function scrub(string $message): string
    {
        $masked = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', fn ($m) => ActivityRedactor::maskEmail($m[0]) ?? '[correo]', $message);

        return mb_substr(trim((string) $masked), 0, 200);
    }
}
