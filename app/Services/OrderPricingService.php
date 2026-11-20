<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for order prices. Both POST /api/orders/quote and POST /api/orders use it,
 * so the summary shown to the customer and the order that is stored can never be calculated differently.
 * Prices always come from the database, never from the request.
 */
class OrderPricingService
{
    public function __construct(private readonly PromotionService $promotions, private readonly CouponService $coupons) {}

    public const MAX_QUANTITY = 99;

    /**
     * @param  array<int, array{variant_id: int|string, quantity: int|string}>  $items
     * @return array{
     *     lines: list<array{product_id: int, product_variant_id: int, sku: string, name: string, quantity: int, unit_price_cop: int, original_unit_price_cop: ?int, promotion_id: ?int, subtotal_cop: int, available_quantity: int}>,
     *     unavailable: list<int>,
     *     subtotal_cop: int, savings_cop: int, shipping_cop: int, shipping_pending: bool, discount_cop: int, total_cop: int, currency: string,
     *     coupon: ?array{id: int, code: string, label: string, discount_cop: int}
     * }
     *
     * With a coupon code the coupon is validated against the priced lines (CouponException when it does not apply);
     * `$lockCoupon` is for the order transaction (row lock on the coupon). `$shippingOverride` is only for manual orders.
     */
    public function price(array $items, ?string $couponCode = null, ?string $email = null, bool $lockCoupon = false, ?int $shippingOverride = null): array
    {
        // Merge repeated lines of the same variant.
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) $item['variant_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }
        if ($quantities !== [] && max($quantities) > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['items' => ['La cantidad máxima por producto es '.self::MAX_QUANTITY.'.']]);
        }

        // Only purchasable variants: published variant of a visible product (published, active category).
        $variants = ProductVariant::query()->whereIn('id', array_keys($quantities))->where('is_published', true)
            ->whereHas('product', fn ($query) => $query->visible())->get()->keyBy('id');

        // Promotions that apply right now; the unit price is the promotional one, the original is kept for the history.
        $promotions = $this->promotions->currentForVariants($variants->keys());

        $lines = [];
        $unavailable = [];
        $subtotal = 0;
        $savings = 0;
        foreach ($quantities as $id => $quantity) {
            $variant = $variants->get($id);
            if (! $variant) {
                $unavailable[] = $id;

                continue;
            }
            $promotion = $promotions[$id] ?? null;
            $basePrice = (int) $variant->price_cop;
            $unitPrice = $promotion['price'] ?? $basePrice; // integer COP
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;
            $savings += ($basePrice - $unitPrice) * $quantity;
            $lines[] = [
                'product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'sku' => $variant->sku,
                'name' => $variant->name, 'quantity' => $quantity, 'unit_price_cop' => $unitPrice,
                'original_unit_price_cop' => $promotion ? $basePrice : null, 'promotion_id' => $promotion['promotion_id'] ?? null, 'subtotal_cop' => $lineTotal,
                'available_quantity' => (int) $variant->stock_quantity,
            ];
        }

        // A manual order may carry the shipping the administrator agreed with the customer (integer COP >= 0); the storefront never passes one.
        $shipping = $shippingOverride !== null ? ['amount_cop' => max(0, $shippingOverride), 'pending' => false] : $this->shipping($subtotal);
        $discount = 0;
        $coupon = null;
        if (filled($couponCode)) {
            $result = $this->coupons->evaluate($couponCode, $lines, $email, $lockCoupon);
            $discount = $result['discount_cop'];
            $coupon = ['id' => $result['coupon']->id, 'code' => $result['coupon']->code, 'label' => $this->coupons->label($result['coupon']), 'discount_cop' => $discount];
        }

        return [
            'lines' => $lines,
            'unavailable' => $unavailable,
            'subtotal_cop' => $subtotal,
            'savings_cop' => $savings,
            'shipping_cop' => $shipping['amount_cop'],
            'shipping_pending' => $shipping['pending'],
            'discount_cop' => $discount,
            'total_cop' => $subtotal + $shipping['amount_cop'] - $discount,
            'currency' => 'COP',
            'coupon' => $coupon,
        ];
    }

    /**
     * The summary shown before confirming (storefront and manual order). A coupon that does not apply does not break it: the summary
     * comes back without the coupon plus `coupon_error`. Read-only.
     *
     * @return array<string, mixed>
     */
    public function quote(array $items, ?string $couponCode = null, ?string $email = null, ?int $shippingOverride = null): array
    {
        $couponError = null;
        try {
            $priced = $this->price($items, $couponCode, $email, shippingOverride: $shippingOverride);
        } catch (CouponException $error) {
            $couponError = ['message' => $error->getMessage(), 'reason' => $error->reason];
            $priced = $this->price($items, shippingOverride: $shippingOverride);
        }

        return [
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
        ];
    }

    /**
     * Shipping rule. No rule is defined yet: it is 0 and "pending" (the storefront shows "a coordinar").
     * Add the real rule here; the frontend reads `shipping_pending` and `shipping_cop` and needs no change.
     *
     * @return array{amount_cop: int, pending: bool}
     */
    protected function shipping(int $subtotalCop): array
    {
        return ['amount_cop' => 0, 'pending' => true];
    }
}
