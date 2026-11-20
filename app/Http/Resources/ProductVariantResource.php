<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Set by PromotionService::attachPricing() for the public catalogue.
        $pricing = $this->resource->relationLoaded('pricing') ? $this->resource->getRelation('pricing') : null;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'weight_grams' => $this->weight_grams,
            'grind_type' => $this->grind_type,
            // Final price: the promotional one while a promotion applies.
            'price_cop' => $pricing['price'] ?? $this->price_cop,
            ...($pricing ? [
                'original_price_cop' => $pricing['original'],
                'discount_label' => $pricing['label'],
                'promotion_ends_at' => $pricing['ends_at']?->toIso8601String(),
            ] : []),
            'in_stock' => $this->stock_quantity > 0,
            'available_quantity' => (int) $this->stock_quantity,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        ];
    }
}
