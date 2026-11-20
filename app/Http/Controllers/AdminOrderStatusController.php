<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderPresenter;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Status steps of an order, used by Hoy AND Pedidos (one implementation). All the rules (allowed transitions, payment, locks,
 * history) live in OrderStatusService. There is no "confirm" here: an order becomes "Pagado" only through PaymentService.
 * `expected_status` is the status the person was looking at: if somebody else moved the order first, the answer is 409.
 */
class AdminOrderStatusController extends Controller
{
    public function __construct(private readonly OrderStatusService $statuses, private readonly OrderPresenter $presenter) {}

    public function prepare(Request $request, int $order): JsonResponse
    {
        [$note, $expected] = $this->common($request);

        return $this->respond($request, $this->statuses->startPreparing(Order::findOrFail($order), $request->user(), $note, $expected));
    }

    public function ready(Request $request, int $order): JsonResponse
    {
        [$note, $expected] = $this->common($request);

        return $this->respond($request, $this->statuses->markReady(Order::findOrFail($order), $request->user(), $note, $expected));
    }

    public function ship(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'delivery_user_id' => ['nullable', 'integer', 'min:1'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'expected_status' => ['nullable', 'string', 'max:40'],
        ], [], ['delivery_user_id' => 'repartidor', 'carrier' => 'transportadora', 'tracking_number' => 'guía']);

        return $this->respond($request, $this->statuses->ship(Order::findOrFail($order), $request->user(), $data, $data['note'] ?? null, $data['expected_status'] ?? null));
    }

    public function reassign(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['delivery_user_id' => ['required', 'integer', 'min:1']], [], ['delivery_user_id' => 'repartidor']);

        return $this->respond($request, $this->statuses->reassign(Order::findOrFail($order), $request->user(), (int) $data['delivery_user_id']));
    }

    public function deliver(Request $request, int $order): JsonResponse
    {
        [$note, $expected] = $this->common($request);

        return $this->respond($request, $this->statuses->deliver(Order::findOrFail($order), $request->user(), $note, $expected));
    }

    /** @return array{0: ?string, 1: ?string} note, expected status */
    private function common(Request $request): array
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500'], 'expected_status' => ['nullable', 'string', 'max:40']]);

        return [$data['note'] ?? null, $data['expected_status'] ?? null];
    }

    private function respond(Request $request, Order $order): JsonResponse
    {
        $order->load(['items' => fn ($query) => $query->orderBy('id'), 'history.user:id,name', 'assignedDelivery:id,name']);

        return response()->json(['order' => $this->presenter->detail($order, $request->user())])->header('Cache-Control', 'private, no-store');
    }
}
