<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\OrderCreationService;
use App\Services\OrderPricingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Registers a guest order. Creating the order is NOT a payment: it is stored as pending/pending and no
 * payment row is created here. Prices and totals always come from the database (OrderPricingService),
 * never from the request.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderPricingService $pricing, private readonly OrderCreationService $orders) {}

    /** Summary shown before confirming. Read-only: it stores nothing. */
    public function quote(QuoteOrderRequest $request): JsonResponse
    {
        // A coupon that does not apply does not break the quote: the summary comes back without it and says why (OrderPricingService::quote).
        return response()->json(['data' => $this->pricing->quote($request->validated('items'), $request->validated('coupon_code'), $request->validated('customer_email'))])
            ->header('Cache-Control', 'no-store');
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Idempotency: the public reference is derived from the client key and is unique in `orders`.
        $reference = 'CFH-'.strtoupper(substr(hash('sha256', $data['idempotency_key']), 0, 12));
        $email = $data['customer_email'];

        $existing = Order::where('reference', $reference)->first();
        if ($existing) {
            return $this->replay($existing, $email);
        }

        try {
            $order = $this->orders->create($data, $reference);
        } catch (QueryException $error) {
            // A concurrent request with the same key created the order first.
            if ((string) $error->getCode() === '23000' && ($existing = Order::where('reference', $reference)->first())) {
                return $this->replay($existing, $email);
            }
            throw $error;
        }

        return $this->respond($order, 201);
    }

    private function replay(Order $order, string $email): JsonResponse
    {
        // The same key with a different customer is not a retry.
        if (mb_strtolower($order->customer_email) !== $email) {
            return response()->json(['message' => 'Esta solicitud ya fue procesada con otros datos. Recarga la página e inténtalo de nuevo.'], 409);
        }

        return $this->respond($order, 200);
    }

    private function respond(Order $order, int $status): JsonResponse
    {
        return response()->json(['order' => [
            'reference' => $order->reference, 'total_cop' => $order->total_cop, 'discount_cop' => $order->discount_cop, 'coupon_code' => $order->coupon_code, 'status' => $order->status, 'payment_status' => $order->payment_status,
        ]], $status)->header('Cache-Control', 'no-store');
    }
}
