<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Hoy": what has to move now, in four sections (oldest first). One query for the orders, with their items and courier
 * eager-loaded; the counters are counted from that same list, so they can never differ from it. Shaped by OrderPresenter:
 * dispatch gets no money, coupon, payments or email; delivery gets only their own orders in "shipping".
 */
class AdminTodayController extends Controller
{
    public function __construct(private readonly OrderPresenter $presenter) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $delivery = ! $user->hasPermission('orders.view_all');

        // While payment is not required before fulfilment, unpaid (pending) orders can already be started.
        $sections = $delivery
            ? ['shipping' => ['shipped']]
            : [
                'new' => config('shop.require_payment_before_fulfillment') ? ['confirmed'] : ['pending', 'confirmed'],
                'preparing' => ['preparing'], 'ready' => ['ready'], 'shipping' => ['shipped'],
            ];

        $orders = Order::query()->valid()->select('orders.*')
            ->whereIn('orders.status', array_merge(...array_values($sections)))
            ->when($delivery, fn ($query) => $query->where('orders.assigned_delivery_user_id', $user->id))
            ->when($user->hasPermission('orders.view_financials'), fn ($query) => $query->selectRaw(OrderPresenter::VERIFIED_PAYMENT.' as payment_verified'))
            ->with(['items' => fn ($query) => $query->orderBy('id'), 'assignedDelivery:id,name'])
            ->orderBy('orders.status_changed_at')->orderBy('orders.id')->get();

        $data = [];
        foreach ($sections as $key => $statuses) {
            $data[$key] = $orders->whereIn('status', $statuses)->map(fn (Order $order) => $this->presenter->card($order, $user))->values();
        }

        return response()->json([
            'sections' => $data,
            'counts' => array_map(fn ($cards) => count($cards), $data),
            // Approved payments on cancelled orders nobody reviewed yet: only the people who can review them (admin) get the alert.
        ] + ($user->hasPermission('payments.review') ? ['payment_alerts' => app(\App\Services\PaymentService::class)->pendingReviews()] : []) + [
            // People an order can be handed to in the "Enviar" panel (only for those who may ship).
            'couriers' => $user->hasPermission('orders.ship')
                ? User::query()->where('is_active', true)->where('role', 'delivery')->orderBy('name')->get(['id', 'name'])->map(fn (User $courier) => ['id' => $courier->id, 'name' => $courier->name])->values()
                : [],
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
