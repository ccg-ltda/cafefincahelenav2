<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderNotification;
use App\Services\OrderNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Reenviar" of an email of an order. Admin only (emails.manage). Sends with the current order data; the Historial records who did it. */
class AdminOrderNotificationController extends Controller
{
    public function __construct(private readonly OrderNotificationService $notifications) {}

    public function resend(Request $request, int $order, int $notification): JsonResponse
    {
        $found = Order::query()->findOrFail($order);
        $row = OrderNotification::query()->where('order_id', $found->id)->findOrFail($notification);
        $this->notifications->resend($row, $request->user());

        return response()->json(['notifications' => $this->notifications->listFor($found)])->header('Cache-Control', 'private, no-store');
    }
}
