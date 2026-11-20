<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

/**
 * The ONLY place that shapes an order for the admin JSON. What a person may see is decided here, in the server, from their
 * permissions: email (orders.view_customer_email) and money, coupon and payments (orders.view_financials) are never put in the
 * response of a role without them, so no frontend trick can show them.
 */
class OrderPresenter
{
    /** Same evidence the dashboard requires before it counts a payment as confirmed. */
    public const VERIFIED_PAYMENT = "EXISTS (SELECT 1 FROM payments WHERE payments.order_id = orders.id AND payments.status = 'approved' AND payments.verified_at IS NOT NULL AND payments.confirmed_at IS NOT NULL AND payments.external_transaction_id IS NOT NULL AND payments.external_transaction_id <> '' AND payments.currency = 'COP' AND payments.amount_cop = orders.total_cop)";

    /** An approved payment on a cancelled order that no administrator has reviewed yet (Pedidos paints the row red). */
    public const PAYMENT_ALERT = "EXISTS (SELECT 1 FROM payments WHERE payments.order_id = orders.id AND payments.review_required_at IS NOT NULL AND payments.reviewed_at IS NULL)";

    public function __construct(private readonly OrderStatusService $statuses) {}

    /** A row of the list. */
    public function row(Order $order, User $user): array
    {
        $row = [
            'id' => $order->id, 'reference' => $order->reference, 'channel' => $order->channel, 'created_at' => $order->created_at?->toIso8601String(),
            'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone, 'delivery_city' => $order->delivery_city,
            'status' => $order->status, 'stage_since' => ($order->status_changed_at ?? $order->created_at)?->toIso8601String(), 'next' => $this->next($order, $user),
        ];
        if ($user->hasPermission('orders.view_financials')) {
            $row += [
                'total_cop' => $order->total_cop, 'coupon_code' => $order->coupon_code, 'discount_cop' => $order->discount_cop,
                'payment_status' => $order->payment_status, 'payment_verified' => (bool) ($order->payment_verified ?? false), 'payment_alert' => (bool) ($order->payment_alert ?? false),
            ];
        }

        return $row;
    }

    /** The detail. Relations `items`, `payments`, `history.user` and `assignedDelivery` are expected to be loaded. */
    public function detail(Order $order, User $user): array
    {
        $financial = $user->hasPermission('orders.view_financials');

        $detail = $this->row($order, $user) + [
            'customer_phone' => $order->customer_phone, 'delivery_address' => $order->delivery_address, 'delivery_details' => $order->delivery_details,
            'order_notes' => $order->order_notes, 'delivery_department' => $order->delivery_department,
            'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku, 'quantity' => $item->quantity]
                + ($financial ? ['unit_price_cop' => $item->unit_price_cop, 'subtotal_cop' => $item->subtotal_cop] : []))->values(),
            'carrier' => $order->carrier, 'tracking_number' => $order->tracking_number,
            'assigned_delivery' => $order->assignedDelivery ? ['id' => $order->assignedDelivery->id, 'name' => $order->assignedDelivery->name] : null,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(), 'shipped_at' => $order->shipped_at?->toIso8601String(), 'delivered_at' => $order->delivered_at?->toIso8601String(),
            'delivery_note' => $order->delivery_note,
            'history' => $order->history->map(fn ($step) => [
                'id' => $step->id, 'from' => $step->from_status, 'to' => $step->to_status, 'note' => $step->note, 'user' => $step->user?->name, 'at' => $step->created_at?->toIso8601String(),
            ])->values(),
        ];
        if ($user->hasPermission('orders.ship') && in_array($order->status, ['ready', 'shipped'], true)) {
            // People the order can be handed to (active delivery role). Only for those who may ship.
            $detail['couriers'] = User::query()->where('is_active', true)->where('role', 'delivery')->orderBy('name')->get(['id', 'name'])->map(fn (User $courier) => ['id' => $courier->id, 'name' => $courier->name])->values();
        }
        if ($user->hasPermission('orders.view_customer_email')) {
            $detail['customer_email'] = $order->customer_email;
        }
        if ($user->hasPermission('orders.create_manual') && $order->channel === 'manual') {
            $open = $order->status === 'pending' && $order->payment_status === 'pending' ? app(PaymentService::class)->openAttempt($order) : null;
            $detail['manual'] = [
                'created_by' => $order->created_by ? User::query()->whereKey($order->created_by)->value('name') : null,
                'payment_due_at' => $order->payment_due_at?->toIso8601String(),
                // none: nothing to pay any more | valid: copy it | expired: a new one may be generated
                'link' => $order->status !== 'pending' || $order->payment_status !== 'pending' ? 'none' : ($open ? 'valid' : 'expired'),
                'link_expires_at' => $open?->checkout_expires_at?->toIso8601String(),
            ];
        }
        if ($financial) {
            $detail += [
                'subtotal_cop' => $order->subtotal_cop, 'shipping_cop' => $order->shipping_cop, 'currency' => $order->currency, 'paid_at' => $order->paid_at?->toIso8601String(),
                'payments' => $order->payments->map(fn ($payment) => [
                    'id' => $payment->id, 'provider' => $payment->provider, 'reference' => $payment->reference,
                    'external_transaction_id' => $payment->external_transaction_id, 'status' => $payment->status,
                    'amount_cop' => $payment->amount_cop, 'currency' => $payment->currency, 'payment_method_type' => $payment->payment_method_type, 'status_message' => $payment->status_message,
                    'created_at' => $payment->created_at?->toIso8601String(), 'review_required_at' => $payment->review_required_at?->toIso8601String(), 'reviewed_at' => $payment->reviewed_at?->toIso8601String(),
                    'confirmed_at' => $payment->confirmed_at?->toIso8601String(), 'verified_at' => $payment->verified_at?->toIso8601String(),
                ])->values(),
            ];
        }

        if ($user->hasPermission('emails.manage')) {
            $detail['notifications'] = app(OrderNotificationService::class)->listFor($order);
        }

        return $detail;
    }

    /** The button of the next step for THIS person (null when there is none or their role may not take it). The endpoints check it again. */
    private function next(Order $order, User $user): ?array
    {
        $action = $this->statuses->nextAction($order);
        if (! $action || ! $user->hasPermission($action['permission'])) {
            return null;
        }
        if ($action['step'] === 'deliver' && ! $user->hasPermission('orders.view_all') && (int) $order->assigned_delivery_user_id !== (int) $user->id) {
            return null; // a delivery person only delivers their own orders
        }

        return ['step' => $action['step'], 'label' => $action['label'], 'done' => $action['done']];
    }

    /** A card of Hoy. Relations `items` and `assignedDelivery` are expected to be loaded. No money, coupon, payment or email unless the person may see them. */
    public function card(Order $order, User $user): array
    {
        return $this->row($order, $user) + [
            'delivery_address' => $order->delivery_address, 'delivery_details' => $order->delivery_details, 'delivery_department' => $order->delivery_department,
            'order_notes' => $order->order_notes,
            'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'quantity' => $item->quantity])->values(),
            'carrier' => $order->carrier, 'tracking_number' => $order->tracking_number,
            'assigned_delivery' => $order->assignedDelivery ? ['id' => $order->assignedDelivery->id, 'name' => $order->assignedDelivery->name] : null,
        ];
    }
}
