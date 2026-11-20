<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Query and row shape of the admin product list (shared by the list, the status toggle and the stock endpoints). */
class AdminProductListing
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function query(): Builder
    {
        // The representative image is read from one variant; it is not a product image.
        return Product::query()->select('products.*')
            ->with(['category:id,name,is_active', 'variants' => fn ($variants) => $variants->orderBy('id')])
            ->withCount([
                'variants',
                'variants as published_variant_count' => fn (Builder $variants) => $variants->where('is_published', true),
            ])->addSelect(['representative_image_path' => ProductVariant::query()->select('image_path')
                ->whereColumn('product_id', 'products.id')->whereNotNull('image_path')->where('image_path', '<>', '')
                ->orderBy('id')->limit(1)]);
    }

    public function find(int $productId): array
    {
        return $this->row($this->query()->whereKey($productId)->firstOrFail());
    }

    /** Administrative state (`product_published`) is stored; store visibility is derived from real data and never saved. */
    public function row(Product $row): array
    {
        $reason = match (true) {
            ! $row->is_published => 'product_inactive',
            ! $row->category->is_active => 'category_inactive',
            $row->variants_count === 0 => 'no_variants',
            $row->published_variant_count === 0 => 'no_active_variants',
            default => null,
        };
        $prices = $row->variants->pluck('price_cop');

        return [
            'product_id' => $row->id, 'product_name' => $row->name, 'product_published' => $row->is_published,
            'variant_count' => $row->variants_count,
            'published_variant_count' => $row->published_variant_count,
            'visible_in_store' => $reason === null,
            'visibility_reason' => $reason,
            'image_url' => $row->representative_image_path ? Storage::disk('public')->url($row->representative_image_path) : null,
            'category_name' => $row->category->name, 'category_active' => $row->category->is_active,
            'min_price_cop' => $prices->min(), 'max_price_cop' => $prices->max(),
            'stock_total' => (int) $row->variants->sum('stock_quantity'),
            'stock_state' => $this->inventory->productState($row->variants),
            'variants' => $row->variants->map(fn (ProductVariant $variant) => $this->variant($variant))->values(),
        ];
    }

    public function variant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id, 'sku' => $variant->sku, 'name' => $variant->name,
            'weight_grams' => $variant->weight_grams, 'grind_type' => $variant->grind_type, 'price_cop' => $variant->price_cop,
            'stock_quantity' => $variant->stock_quantity, 'low_stock_threshold' => $variant->low_stock_threshold,
            'stock_state' => $this->inventory->state((int) $variant->stock_quantity, (int) $variant->low_stock_threshold),
            'is_published' => $variant->is_published,
        ];
    }
}
