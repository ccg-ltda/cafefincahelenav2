<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'name', 'slug', 'short_description', 'description',
        'is_published', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Public visibility: published product, active category and at least one published variant. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
            ->whereHas('variants', fn (Builder $variants) => $variants->where('is_published', true));
    }
}
