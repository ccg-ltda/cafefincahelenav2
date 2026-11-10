<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'sku', 'name', 'weight_grams', 'grind_type',
        'price_cop', 'image_path', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'weight_grams' => 'integer',
            'price_cop' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
