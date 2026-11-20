<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place an order is created: the storefront checkout (Api\OrderController::store) and the administrator's manual order
 * (ManualOrderController) both come here. Prices, promotions, coupon, stock, customer and consents are the same rules for both;
 * only the origin differs (`channel`, `created_by`, shipping agreed by hand, the payment deadline, whether the customer accepted the
 * online terms). The order is always created pending/pending: only PaymentService marks it paid.
 */
class OrderCreationService
{
    public function __construct(
        private readonly OrderPricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly CustomerService $customers,
    ) {}

    /**
     * Runs in its own transaction (it joins the caller's one when there is one): if a line does not fit, nothing is created or deducted.
     *
     * @param  array<string, mixed>  $data  validated customer, delivery and cart data (`customer_email` may be missing for a manual order)
     * @param  array{channel?: string, created_by?: ?int, shipping_cop?: ?int, payment_due_at?: ?CarbonInterface, terms_accepted?: bool}  $options
     */
    public function create(array $data, string $reference, array $options = []): Order
    {
        $channel = $options['channel'] ?? 'web';
        $email = filled($data['customer_email'] ?? null) ? $data['customer_email'] : null;

        return DB::transaction(function () use ($data, $reference, $options, $channel, $email) {
            // Everything is checked again here, with the coupon row locked: two customers cannot spend its last use together.
            $priced = $this->pricing->price($data['items'], $data['coupon_code'] ?? null, $email, lockCoupon: true, shippingOverride: $options['shipping_cop'] ?? null);
            if ($priced['unavailable'] !== []) {
                throw ValidationException::withMessages(['items' => [$channel === 'manual'
                    ? 'Algunos productos ya no están disponibles. Revisa el pedido.'
                    : 'Algunos productos de tu carrito ya no están disponibles. Revisa tu carrito.']]);
            }
            if (isset($data['expected_total_cop']) && (int) $data['expected_total_cop'] !== $priced['total_cop']) {
                throw ValidationException::withMessages(['items' => ['Los precios de tu carrito cambiaron. Revisa el resumen antes de confirmar.']]);
            }

            $customer = $this->customers->syncFromOrder($data, (bool) ($data['marketing_opt_in'] ?? false));
            $order = Order::create([
                'customer_id' => $customer->id, 'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
                'reference' => $reference, 'customer_name' => $data['customer_name'], 'customer_email' => $email,
                'customer_phone' => $data['customer_phone'], 'delivery_address' => $data['delivery_address'],
                'delivery_details' => $data['delivery_details'] ?? null, 'delivery_city' => $data['delivery_city'],
                'delivery_department' => $data['delivery_department'], 'order_notes' => $data['order_notes'] ?? null,
                'subtotal_cop' => $priced['subtotal_cop'], 'shipping_cop' => $priced['shipping_cop'],
                'discount_cop' => $priced['discount_cop'], 'total_cop' => $priced['total_cop'],
                'coupon_id' => $priced['coupon']['id'] ?? null, 'coupon_code' => $priced['coupon']['code'] ?? null,
                'currency' => $priced['currency'], 'status' => 'pending', 'payment_status' => 'pending',
                'channel' => $channel, 'created_by' => $options['created_by'] ?? null, 'payment_due_at' => $options['payment_due_at'] ?? null,
                // A manual order's customer never ticked the online terms; the data consent is what the administrator vouched for.
                'terms_accepted_at' => ($options['terms_accepted'] ?? true) ? now() : null, 'data_consent_at' => now(),
            ]);
            $order->items()->createMany(array_map(fn (array $line) => Arr::except($line, ['available_quantity']), $priced['lines']));
            // Same transaction as the order: if any line does not fit, the order is not created and nothing is deducted.
            if ($priced['coupon']) {
                $this->coupons->redeem($order, Coupon::findOrFail($priced['coupon']['id']), $email ?? '', $priced['discount_cop']);
            }
            $this->inventory->sellOrder($order, array_column($priced['lines'], 'quantity', 'product_variant_id'), $options['created_by'] ?? null);

            return $order;
        });
    }
}
