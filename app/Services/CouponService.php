<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Every coupon rule lives here: code format, status, validation against a priced cart, the discount amount, redemptions
 * and their release, and the validation of the admin form. OrderPricingService calls it, so quote and store agree.
 *
 * A coupon discounts products only (the "eligible subtotal": lines at their final price, minus promoted lines when the
 * coupon excludes them), never shipping. Amounts are integer COP rounded to the hundred.
 */
class CouponService
{
    public const TYPES = ['percentage', 'fixed_amount'];

    public const MAX_PERCENTAGE = 90;

    public const CODE_REGEX = '/^[A-Z0-9-]{3,30}$/';

    /** current | scheduled | expired | exhausted | inactive */
    public function status(Coupon $coupon, ?int $uses = null, ?CarbonInterface $at = null): string
    {
        $at ??= now();
        if (! $coupon->is_active) {
            return 'inactive';
        }
        if ($coupon->ends_at && $coupon->ends_at->lte($at)) {
            return 'expired';
        }
        if ($coupon->starts_at && $coupon->starts_at->gt($at)) {
            return 'scheduled';
        }
        if ($coupon->max_uses !== null && ($uses ?? $coupon->activeRedemptions()->count()) >= $coupon->max_uses) {
            return 'exhausted';
        }

        return 'current';
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $at = now();
        $counts = [];
        foreach (['current', 'scheduled', 'expired', 'exhausted', 'inactive'] as $status) {
            $counts[$status] = Coupon::query()->inStatus($status, $at)->count();
        }

        return $counts;
    }

    /** Uppercase, trimmed code, or null when it cannot be a code (never a database lookup). */
    public function normalize(?string $code): ?string
    {
        $code = mb_strtoupper(trim((string) $code));

        return preg_match(self::CODE_REGEX, $code) ? $code : null;
    }

    /**
     * Checks the coupon against a priced cart and returns the discount. Throws CouponException with the customer message.
     * With `$lock` (inside the order transaction) the coupon row is locked so two orders cannot spend the last use together.
     *
     * @param  list<array{subtotal_cop: int, original_unit_price_cop: ?int}>  $lines
     * @return array{coupon: Coupon, discount_cop: int, eligible_subtotal_cop: int}
     */
    public function evaluate(?string $code, array $lines, ?string $email, bool $lock = false): array
    {
        $normalized = $this->normalize($code);
        $query = Coupon::query()->where('code', $normalized ?? '');
        $coupon = $normalized ? ($lock ? $query->lockForUpdate()->first() : $query->first()) : null;
        $at = now();

        // Unknown, inactive, expired or not yet started: the same answer, so codes cannot be probed.
        if (! $coupon || ! $coupon->is_active || ($coupon->ends_at && $coupon->ends_at->lte($at)) || ($coupon->starts_at && $coupon->starts_at->gt($at))) {
            throw new CouponException(CouponException::GENERIC, 'invalid');
        }
        if ($coupon->max_uses !== null && $coupon->activeRedemptions()->count() >= $coupon->max_uses) {
            throw new CouponException('Este cupón ya alcanzó su límite de usos', 'exhausted');
        }

        $eligible = array_sum(array_map(
            fn (array $line) => $coupon->exclude_promoted_items && $line['original_unit_price_cop'] !== null ? 0 : $line['subtotal_cop'],
            $lines,
        ));
        if ($eligible <= 0) {
            throw new CouponException($coupon->exclude_promoted_items ? 'Este cupón no aplica a productos en promoción' : 'Este cupón no aplica a tu carrito', 'not_eligible');
        }
        if ($eligible < $coupon->min_subtotal_cop) {
            throw new CouponException('Compra mínima de $ '.number_format($coupon->min_subtotal_cop, 0, ',', '.'), 'minimum');
        }
        $discount = $this->discountFor($coupon, $eligible);
        if ($discount <= 0) {
            throw new CouponException('Este cupón no genera descuento en tu pedido', 'no_discount');
        }

        return ['coupon' => $coupon, 'discount_cop' => $discount, 'eligible_subtotal_cop' => $eligible];
    }

    /** Percentage (capped by `max_discount_cop`) or fixed value, rounded to the hundred, never above the eligible subtotal. */
    public function discountFor(Coupon $coupon, int $eligibleSubtotal): int
    {
        $raw = $coupon->discount_type === 'percentage' ? $eligibleSubtotal * $coupon->discount_value / 100 : $coupon->discount_value;
        $discount = (int) (round($raw / 100) * 100);
        if ($coupon->discount_type === 'percentage' && $coupon->max_discount_cop !== null) {
            $discount = min($discount, $coupon->max_discount_cop);
        }

        return max(0, min($discount, $eligibleSubtotal));
    }

    public function label(Coupon $coupon): string
    {
        return $coupon->discount_type === 'percentage' ? "-{$coupon->discount_value}%" : '-$ '.number_format($coupon->discount_value, 0, ',', '.');
    }

    /** One redemption per order (`order_id` is unique, so a replayed request can never register a second use). */
    public function redeem(Order $order, Coupon $coupon, string $email, int $discount): CouponRedemption
    {
        return CouponRedemption::create(['coupon_id' => $coupon->id, 'order_id' => $order->id, 'email' => mb_strtolower($email), 'discount_cop' => $discount]);
    }

    /** Gives the use back (to be called when an order is cancelled). Idempotent; returns whether something was released. */
    public function releaseForOrder(Order $order): bool
    {
        return CouponRedemption::query()->where('order_id', $order->id)->whereNull('released_at')->update(['released_at' => now()]) > 0;
    }

    /** Converts "2026-11-19T08:30" typed in Colombian time to a UTC Carbon instance. */
    public function parseLocal(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::createFromFormat('Y-m-d\TH:i', $value, PromotionService::TIMEZONE)->utc() : null;
    }

    public function formatLocal(?CarbonInterface $value): ?string
    {
        return $value?->copy()->timezone(PromotionService::TIMEZONE)->format('Y-m-d\TH:i');
    }

    /** Business rules of the admin form (shape is checked by SaveCouponRequest). Throws a 422 keyed by form field. */
    public function validateForSave(array $data, ?int $ignoreId = null): void
    {
        $errors = [];
        if (! preg_match(self::CODE_REGEX, $data['code'])) {
            $errors['code'][] = 'Usa solo letras, números y guiones, de 3 a 30 caracteres.';
        } elseif (Coupon::query()->where('code', $data['code'])->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $errors['code'][] = 'Ya existe un cupón con este código.';
        }
        if ($data['discount_type'] === 'percentage' && ($data['discount_value'] < 1 || $data['discount_value'] > self::MAX_PERCENTAGE)) {
            $errors['discount_value'][] = 'El porcentaje debe estar entre 1 y '.self::MAX_PERCENTAGE.'.';
        }
        if ($data['discount_type'] === 'fixed_amount' && $data['max_discount_cop'] !== null) {
            $errors['max_discount_cop'][] = 'El descuento máximo solo aplica a porcentajes.';
        }
        if ($data['starts_at'] && $data['ends_at'] && $data['ends_at']->lte($data['starts_at'])) {
            $errors['ends_at'][] = 'La fecha de fin debe ser posterior a la de inicio.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
