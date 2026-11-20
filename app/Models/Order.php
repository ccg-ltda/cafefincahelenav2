<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Since when the order is in its current stage (Hoy shows "hace 25 min"); OrderStatusService updates it on every move.
        static::creating(fn (Order $order) => $order->status_changed_at ??= now());
    }

    protected function casts(): array
    {
        return ['subtotal_cop' => 'integer', 'shipping_cop' => 'integer', 'discount_cop' => 'integer', 'total_cop' => 'integer', 'paid_at' => 'datetime', 'confirmed_at' => 'datetime', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'status_changed_at' => 'datetime', 'terms_accepted_at' => 'datetime', 'data_consent_at' => 'datetime', 'marketing_opt_in' => 'boolean'];
    }

    /**
     * Orders that count as real purchases for customer figures: not cancelled and, when the shop requires payment
     * (config shop.require_payment_before_fulfillment), paid.
     */
    public function scopeValid(Builder $query): Builder
    {
        $query->where('orders.status', '<>', 'cancelled');

        return config('shop.require_payment_before_fulfillment') ? $query->where('orders.payment_status', 'paid') : $query;
    }

    public function assignedDelivery(): BelongsTo { return $this->belongsTo(User::class, 'assigned_delivery_user_id'); }
    public function history(): HasMany { return $this->hasMany(OrderStatusHistory::class)->orderBy('id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function coupon(): BelongsTo { return $this->belongsTo(Coupon::class); }
    public function couponRedemption(): HasOne { return $this->hasOne(CouponRedemption::class); }
}
