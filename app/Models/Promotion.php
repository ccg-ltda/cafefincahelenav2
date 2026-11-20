<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount_value' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'promotion_variant');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Active and inside its dates (a missing date is open-ended). Publication of the product is checked by the callers. */
    public function scopeCurrent(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /** @param  string  $status  current | scheduled | expired | inactive */
    public function scopeInStatus(Builder $query, string $status, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return match ($status) {
            'current' => $query->current($at),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $at),
            'expired' => $query->where('is_active', true)->where('ends_at', '<=', $at),
            'inactive' => $query->where('is_active', false),
            default => $query,
        };
    }
}
