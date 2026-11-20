<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\CouponException;
use App\Http\Requests\QuoteOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Coupon;
use App\Models\Order;
use App\Services\CouponService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrderPricingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a guest order. Creating the order is NOT a payment: it is stored as pending/pending and no
 * payment row is created here. Prices and totals always come from the database (OrderPricingService),
 * never from the request.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderPricingService $pricing, private readonly InventoryService $inventory, private readonly CouponService $coupons, private readonly CustomerService $customers) {}

    /** Summary shown before confirming. Read-only: it stores nothing. */
    public function quote(QuoteOrderRequest $request): JsonResponse
    {
        // A coupon that does not apply does not break the quote: the summary comes back without it and says why.
        $couponError = null;
        try {
            $priced = $this->pricing->price($request->validated('items'), $request->validated('coupon_code'), $request->validated('customer_email'));
        } catch (CouponException $error) {
            $couponError = ['message' => $error->getMessage(), 'reason' => $error->reason];
            $priced = $this->pricing->price($request->validated('items'));
        }

        return response()->json(['data' => [
            'items' => array_map(fn (array $line) => [
                'variant_id' => $line['product_variant_id'], 'sku' => $line['sku'], 'name' => $line['name'],
                'quantity' => $line['quantity'], 'unit_price_cop' => $line['unit_price_cop'], 'original_unit_price_cop' => $line['original_unit_price_cop'], 'subtotal_cop' => $line['subtotal_cop'],
                'available_quantity' => $line['available_quantity'], 'insufficient' => $line['quantity'] > $line['available_quantity'],
            ], $priced['lines']),
            'unavailable' => $priced['unavailable'],
            'subtotal_cop' => $priced['subtotal_cop'],
            'savings_cop' => $priced['savings_cop'],
            'shipping_cop' => $priced['shipping_cop'],
            'shipping_pending' => $priced['shipping_pending'],
            'discount_cop' => $priced['discount_cop'],
            'total_cop' => $priced['total_cop'],
            'currency' => $priced['currency'],
            'coupon' => $priced['coupon'],
            'coupon_error' => $couponError,
        ]])->header('Cache-Control', 'no-store');
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
            $order = DB::transaction(function () use ($data, $reference) {
                // Everything is checked again here, with the coupon row locked: two customers cannot spend its last use together.
                $priced = $this->pricing->price($data['items'], $data['coupon_code'] ?? null, $data['customer_email'], lockCoupon: true);
                if ($priced['unavailable'] !== []) {
                    throw ValidationException::withMessages(['items' => ['Algunos productos de tu carrito ya no están disponibles. Revisa tu carrito.']]);
                }
                if (isset($data['expected_total_cop']) && (int) $data['expected_total_cop'] !== $priced['total_cop']) {
                    throw ValidationException::withMessages(['items' => ['Los precios de tu carrito cambiaron. Revisa el resumen antes de confirmar.']]);
                }

                $customer = $this->customers->syncFromOrder($data, (bool) ($data['marketing_opt_in'] ?? false));
                $order = Order::create([
                    'customer_id' => $customer->id, 'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
                    'reference' => $reference, 'customer_name' => $data['customer_name'], 'customer_email' => $data['customer_email'],
                    'customer_phone' => $data['customer_phone'], 'delivery_address' => $data['delivery_address'],
                    'delivery_details' => $data['delivery_details'] ?? null, 'delivery_city' => $data['delivery_city'],
                    'delivery_department' => $data['delivery_department'], 'order_notes' => $data['order_notes'] ?? null,
                    'subtotal_cop' => $priced['subtotal_cop'], 'shipping_cop' => $priced['shipping_cop'],
                    'discount_cop' => $priced['discount_cop'], 'total_cop' => $priced['total_cop'],
                    'coupon_id' => $priced['coupon']['id'] ?? null, 'coupon_code' => $priced['coupon']['code'] ?? null,
                    'currency' => $priced['currency'], 'status' => 'pending', 'payment_status' => 'pending',
                    'terms_accepted_at' => now(), 'data_consent_at' => now(),
                ]);
                $order->items()->createMany(array_map(fn (array $line) => Arr::except($line, ['available_quantity']), $priced['lines']));
                // Same transaction as the order: if any line does not fit, the order is not created and nothing is deducted.
                if ($priced['coupon']) {
                    $this->coupons->redeem($order, Coupon::findOrFail($priced['coupon']['id']), $data['customer_email'], $priced['discount_cop']);
                }
                $this->inventory->sellOrder($order, array_column($priced['lines'], 'quantity', 'product_variant_id'));

                return $order;
            });
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
