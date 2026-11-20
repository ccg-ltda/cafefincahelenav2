<?php

namespace App\Services;

use App\Enums\Role;
use App\Events\OrderStatusChanged;
use App\Exceptions\OrderMovedException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place where `orders.status` changes. Every change locks the order, checks the transition, saves the order data of
 * that step, writes an `order_status_history` row and an Historial row (orders/status_changed), all in ONE transaction, and
 * dispatches OrderStatusChanged after the commit.
 *
 * Flow: pending -> confirmed -> preparing -> ready -> shipped -> delivered.
 * pending -> confirmed happens only when a payment is approved (PaymentService), never from the panel. While
 * shop.require_payment_before_fulfillment is false an unpaid order may also go pending -> preparing, to work before Wompi.
 * Cancel and refund are not implemented yet: the order management module will add them here, on top of this same flow.
 */
class OrderStatusService
{
    public const FLOW = ['pending' => 'confirmed', 'confirmed' => 'preparing', 'preparing' => 'ready', 'ready' => 'shipped', 'shipped' => 'delivered'];

    /** Orders still to be worked (what dispatch sees). */
    public const ACTIVE = ['pending', 'confirmed', 'preparing', 'ready', 'shipped'];

    /** With shop.require_payment_before_fulfillment these steps need the order paid. */
    private const NEEDS_PAYMENT = ['preparing', 'ready', 'shipped', 'delivered'];

    /** The ONE list of order states and their Spanish label (/admin/session sends it to the frontend). */
    public const LABELS = [
        'pending' => 'Pendiente de pago', 'confirmed' => 'Pagado', 'preparing' => 'En preparación', 'ready' => 'Preparado',
        'shipped' => 'En camino', 'delivered' => 'Entregado', 'cancelled' => 'Cancelado',
    ];

    public function __construct(private readonly ActivityLogger $activity, private readonly InventoryService $inventory, private readonly CouponService $coupons) {}

    /**
     * Cancels an order that is still waiting for payment (used by the expiry command and by the customer's own "cancelar pedido"; the admin button will come later; `$actorLabel` names who when there is no user: "Sistema" by default, "Cliente"). In ONE
     * transaction: status, stock back (InventoryService, never twice), coupon released (CouponService), order_status_history,
     * Historial (actor "Sistema" unless a person is given) and, after the commit, OrderStatusChanged. Idempotent: an already
     * cancelled order is returned untouched. Only a `pending` order can be cancelled here: a paid or worked one never is.
     */
    public function cancel(Order $order, string $reason, ?User $by = null, ?string $actorLabel = null): Order
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($order, $reason, $by, $actorLabel) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if ($locked->status !== 'pending' || $locked->payment_status === 'paid') {
                throw ValidationException::withMessages(['status' => ['Solo se puede cancelar un pedido que sigue pendiente de pago.']]);
            }

            $from = $locked->status;
            $locked->status = 'cancelled';
            $locked->status_changed_at = now();
            $locked->save();

            $this->inventory->returnOrder($locked, $by?->id);
            $this->coupons->releaseForOrder($locked);

            OrderStatusHistory::create(['order_id' => $locked->id, 'from_status' => $from, 'to_status' => 'cancelled', 'note' => $reason, 'user_id' => $by?->id]);
            $this->activity->log('orders', 'status_changed', $locked, ['status' => [$from, 'cancelled']], ['note' => $reason], actor: $by, actorLabel: $by ? null : ($actorLabel ?? 'Sistema'));

            DB::afterCommit(fn () => OrderStatusChanged::dispatch($locked, $from, 'cancelled', $by));

            return $locked;
        });
    }

    /**
     * The next step of an order as the buttons show it (Hoy and Pedidos use the same one), or null. The server checks the
     * permission again on every endpoint.
     *
     * @return array{step: string, label: string, done: string, permission: string}|null
     */
    public function nextAction(Order $order): ?array
    {
        $unpaidMayStart = ! config('shop.require_payment_before_fulfillment');

        return match ($order->status) {
            'pending' => $unpaidMayStart && $order->channel !== 'manual' ? $this->action('prepare') : null, // a manual order waits for Wompi
            'confirmed' => $this->action('prepare'),
            'preparing' => $this->action('ready'),
            'ready' => $this->action('ship'),
            'shipped' => $this->action('deliver'),
            default => null,
        };
    }

    private function action(string $step): array
    {
        return match ($step) {
            'prepare' => ['step' => 'prepare', 'permission' => 'orders.prepare', 'label' => 'Iniciar preparación', 'done' => 'Preparación iniciada'],
            'ready' => ['step' => 'ready', 'permission' => 'orders.prepare', 'label' => 'Pedido preparado', 'done' => 'Pedido preparado'],
            'ship' => ['step' => 'ship', 'permission' => 'orders.ship', 'label' => 'Enviar', 'done' => 'Pedido en camino'],
            'deliver' => ['step' => 'deliver', 'permission' => 'orders.deliver', 'label' => 'Entregado', 'done' => 'Pedido entregado'],
        };
    }

    /** pending -> confirmed. Only PaymentService calls it (a payment was approved): there is no endpoint for it. */
    public function confirmPayment(Order $order, ?User $by = null): Order
    {
        return $this->move($order, 'confirmed', $by, null);
    }

    /** "Iniciar preparación": confirmed (or pending while payment is not required) -> preparing. */
    public function startPreparing(Order $order, User $by, ?string $note = null, ?string $expected = null): Order
    {
        return $this->move($order, 'preparing', $by, $note, null, $expected);
    }

    /** "Pedido preparado": preparing -> ready. */
    public function markReady(Order $order, User $by, ?string $note = null, ?string $expected = null): Order
    {
        return $this->move($order, 'ready', $by, $note, null, $expected);
    }

    /**
     * Ships with a delivery person (an active user with the delivery role) OR a carrier (tracking number optional): exactly one.
     *
     * @param  array{delivery_user_id?: ?int, carrier?: ?string, tracking_number?: ?string}  $data
     */
    public function ship(Order $order, User $by, array $data, ?string $note = null, ?string $expected = null): Order
    {
        $userId = $data['delivery_user_id'] ?? null;
        $carrier = filled($data['carrier'] ?? null) ? trim($data['carrier']) : null;
        if (($userId === null) === ($carrier === null)) {
            throw ValidationException::withMessages(['delivery_user_id' => ['Elige un repartidor o escribe una transportadora (solo una de las dos).']]);
        }

        return $this->move($order, 'shipped', $by, $note, function (Order $locked) use ($userId, $carrier, $data) {
            $courier = null;
            if ($userId !== null) {
                $courier = User::query()->whereKey($userId)->where('is_active', true)->where('role', Role::Delivery->value)->first();
                if (! $courier) {
                    throw ValidationException::withMessages(['delivery_user_id' => ['Elige un repartidor activo.']]);
                }
            }
            $locked->forceFill([
                'assigned_delivery_user_id' => $courier?->id, 'carrier' => $carrier,
                'tracking_number' => $carrier && filled($data['tracking_number'] ?? null) ? trim($data['tracking_number']) : null,
            ]);

            return array_filter(['delivery' => $courier?->name, 'carrier' => $carrier, 'tracking_number' => $locked->tracking_number]);
        }, $expected);
    }

    /** Moves a shipped order to another active delivery person (no status change). One Historial row. */
    public function reassign(Order $order, User $by, int $deliveryUserId): Order
    {
        return DB::transaction(function () use ($order, $by, $deliveryUserId) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'shipped') {
                throw ValidationException::withMessages(['status' => ['Solo se puede reasignar un pedido en camino.']]);
            }
            $courier = User::query()->whereKey($deliveryUserId)->where('is_active', true)->where('role', Role::Delivery->value)->first();
            if (! $courier) {
                throw ValidationException::withMessages(['delivery_user_id' => ['Elige un repartidor activo.']]);
            }
            $before = $locked->assignedDelivery?->name;
            $locked->forceFill(['assigned_delivery_user_id' => $courier->id, 'carrier' => null, 'tracking_number' => null])->save();
            $this->activity->log('orders', 'updated', $locked, ['assigned_delivery_user_id' => [$before, $courier->name]], actor: $by);

            return $locked;
        });
    }

    /** A person with only the delivery role can deliver just the orders assigned to them. */
    public function deliver(Order $order, User $by, ?string $note = null, ?string $expected = null): Order
    {
        return $this->move($order, 'delivered', $by, $note, function (Order $locked) use ($by, $note) {
            if (! $by->hasPermission('orders.view_all') && (int) $locked->assigned_delivery_user_id !== (int) $by->id) {
                throw new AuthorizationException('Este pedido no está asignado a ti.');
            }
            $locked->forceFill(['delivery_note' => filled($note) ? trim($note) : null]);

            return [];
        }, $expected);
    }

    private function allowed(string $from, string $to): bool
    {
        if ($from === 'pending' && $to === 'preparing') {
            return ! config('shop.require_payment_before_fulfillment'); // work before Wompi is connected
        }

        return (self::FLOW[$from] ?? null) === $to;
    }

    /**
     * @param  (callable(Order): array<string, mixed>)|null  $apply  runs on the locked order: validates and sets the data of the step, returns extra context for the Historial
     * @param  string|null  $expected  the status the person was looking at: if somebody else already moved the order, nothing is saved (409)
     */
    private function move(Order $order, string $to, ?User $by, ?string $note, ?callable $apply = null, ?string $expected = null): Order
    {
        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($order, $to, $by, $note, $apply, $expected) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;

            if ($expected !== null && $expected !== $from) {
                $who = $locked->history()->with('user:id,name')->latest('id')->first()?->user?->name;
                $name = $who ? mb_convert_case($who, MB_CASE_TITLE, 'UTF-8') : 'Otra persona';
                throw new OrderMovedException($name.' ya movió este pedido a "'.(self::LABELS[$from] ?? $from).'".', $from);
            }
            if (! $this->allowed($from, $to)) {
                throw ValidationException::withMessages(['status' => ['No se puede pasar el pedido de "'.(self::LABELS[$from] ?? $from).'" a "'.self::LABELS[$to].'".']]);
            }
            if ((config('shop.require_payment_before_fulfillment') || $locked->channel === 'manual') && in_array($to, self::NEEDS_PAYMENT, true) && $locked->payment_status !== 'paid') {
                throw ValidationException::withMessages(['status' => ['El pedido debe estar pagado antes de pasar a "'.self::LABELS[$to].'".']]);
            }

            $extra = $apply ? $apply($locked) : [];
            $locked->status = $to;
            $locked->status_changed_at = now();
            $stamp = ['confirmed' => 'confirmed_at', 'shipped' => 'shipped_at', 'delivered' => 'delivered_at'][$to] ?? null;
            if ($stamp) {
                $locked->forceFill([$stamp => now()]);
            }
            $locked->save();

            OrderStatusHistory::create(['order_id' => $locked->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'user_id' => $by?->id]);
            $this->activity->log('orders', 'status_changed', $locked, ['status' => [$from, $to]], array_filter(['note' => $note] + $extra), actor: $by, actorLabel: $by ? null : 'Sistema');

            DB::afterCommit(fn () => OrderStatusChanged::dispatch($locked, $from, $to, $by));

            return $locked;
        });
    }
}
