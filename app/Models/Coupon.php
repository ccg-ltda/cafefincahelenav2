<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer', 'max_discount_cop' => 'integer', 'min_subtotal_cop' => 'integer', 'max_uses' => 'integer',
            'exclude_promoted_items' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Uses that still count (a released redemption frees its use). */
    public function activeRedemptions(): HasMany
    {
        return $this->redemptions()->whereNull('released_at');
    }

    private const USES_SQL = '(select count(*) from coupon_redemptions where coupon_redemptions.coupon_id = coupons.id and coupon_redemptions.released_at is null)';

    /** @param  string  $status  current | scheduled | expired | exhausted | inactive (same order of precedence as CouponService::status) */
    public function scopeInStatus(Builder $query, string $status, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();
        $open = fn (Builder $q) => $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $at));

        return match ($status) {
            'inactive' => $query->where('is_active', false),
            'expired' => $query->where('is_active', true)->where('ends_at', '<=', $at),
            'scheduled' => $query->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))->where('starts_at', '>', $at),
            'exhausted' => $query->where($open)->whereNotNull('max_uses')->whereRaw(self::USES_SQL.' >= max_uses'),
            'current' => $query->where($open)->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereRaw(self::USES_SQL.' < max_uses')),
            default => $query,
        };
    }
}
