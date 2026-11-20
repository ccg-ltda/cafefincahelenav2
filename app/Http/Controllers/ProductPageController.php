<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Serves /producto/{slug} with real HTTP semantics (200, 301, 404) and the SEO data
 * (title, description, canonical, Open Graph, JSON-LD) in the initial HTML.
 * The React app still renders the page itself.
 */
class ProductPageController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    private const BRAND = 'Café Finca Helena';

    public function show(Request $request, string $slug)
    {
        $slug = Str::lower($slug);

        $product = Product::query()->visible()
            ->with(['category', 'variants' => fn ($variants) => $variants->where('is_published', true)->orderBy('id')])
            ->where('slug', $slug)
            ->first();

        if ($product) {
            $this->promotions->attachPricing([$product]);

            return response()->view('app', ['seo' => $this->seo($product)]);
        }

        // Legacy SKU URLs (/producto/fh-001) → one 301 to the descriptive canonical URL.
        $variant = ProductVariant::query()->where('is_published', true)->whereRaw('lower(sku) = ?', [$slug])
            ->whereHas('product', fn ($query) => $query->visible())->with('product:id,slug')->first();
        if ($variant) {
            $siblings = ProductVariant::query()->where('product_id', $variant->product_id)->where('is_published', true)->count();
            $target = '/producto/'.$variant->product->slug.($siblings > 1 ? '?sku='.rawurlencode($variant->sku) : '');

            return redirect($target, 301);
        }

        // Slugs replaced by a descriptive one (table created by an additive migration).
        if (Schema::hasTable('product_slug_redirects')) {
            $current = DB::table('product_slug_redirects as redirects')->join('products', 'products.id', '=', 'redirects.product_id')
                ->where('redirects.slug', $slug)->value('products.slug');
            if ($current && $current !== $slug && Product::query()->visible()->where('slug', $current)->exists()) {
                return redirect('/producto/'.$current, 301);
            }
        }

        return response()->view('app', ['seo' => ['title' => 'Producto no encontrado | '.self::BRAND, 'noindex' => true]], 404);
    }

    private function seo(Product $product): array
    {
        $variants = $product->variants;
        $canonical = $this->absolute('/producto/'.$product->slug);
        $image = $variants->first(fn ($variant) => $variant->image_path)?->image_path;
        $imageUrl = $image ? $this->absolute(Storage::disk('public')->url($image)) : null;
        $description = $this->description($product, $variants->map(fn (ProductVariant $variant) => $this->price($variant))->min());
        $title = $product->name.' | '.self::BRAND;

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'image' => $imageUrl,
            'jsonld' => json_encode($this->jsonLd($product, $canonical, $description), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS),
        ];
    }

    /** Built only from stored data; promotional copy that cannot be verified is not reused. */
    private function description(Product $product, int $minPrice): string
    {
        // A product with several variants whose description spans several lines holds one note per
        // presentation (see ProductSeeder), so it cannot describe the product as a whole.
        $perVariantNotes = $product->variants->count() > 1 && count(preg_split('/\R/u', trim((string) $product->description), -1, PREG_SPLIT_NO_EMPTY)) > 1;
        $source = $perVariantNotes ? '' : $product->description;
        $text = trim(preg_replace('/\s+/u', ' ', (string) $source));
        if ($text === '' || mb_strtolower($text) === 'n/a' || preg_match('/descuento|precio especial/iu', $text)) {
            $text = $product->name.' de '.self::BRAND.'. Desde $'.number_format($minPrice, 0, ',', '.').' COP.';
        }

        return Str::limit($text, 155, '…', preserveWords: true);
    }

    private function jsonLd(Product $product, string $canonical, string $description): array
    {
        $brand = ['@type' => 'Brand', 'name' => self::BRAND];
        $variants = $product->variants;
        $offer = fn (ProductVariant $variant, string $url) => [
            '@type' => 'Offer', 'url' => $url, 'price' => (string) $this->price($variant), 'priceCurrency' => 'COP',
        ];
        $image = fn (ProductVariant $variant) => $variant->image_path ? $this->absolute(Storage::disk('public')->url($variant->image_path)) : null;

        if ($variants->count() === 1) {
            $variant = $variants->first();

            return array_filter([
                '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product->name, 'description' => $description,
                'sku' => $variant->sku, 'image' => $image($variant), 'brand' => $brand, 'url' => $canonical, 'offers' => $offer($variant, $canonical),
            ], fn ($value) => $value !== null);
        }

        // Availability and inventory are intentionally omitted: no data source confirms them.
        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'ProductGroup', 'name' => $product->name, 'description' => $description,
            'productGroupID' => $product->slug, 'url' => $canonical, 'brand' => $brand, 'variesBy' => ['https://schema.org/size'],
            'hasVariant' => $variants->map(fn (ProductVariant $variant) => array_filter([
                '@type' => 'Product', 'name' => $variant->name, 'sku' => $variant->sku, 'image' => $image($variant), 'brand' => $brand,
                'url' => $canonical.'?sku='.rawurlencode($variant->sku),
                'size' => $variant->weight_grams ? $variant->weight_grams.' g' : null,
                'additionalProperty' => $variant->grind_type ? [['@type' => 'PropertyValue', 'name' => 'Molienda', 'value' => $variant->grind_type]] : null,
                'offers' => $offer($variant, $canonical.'?sku='.rawurlencode($variant->sku)),
            ], fn ($value) => $value !== null))->values()->all(),
        ], fn ($value) => $value !== null);
    }

    /** Final price (promotional while a promotion applies). */
    private function price(ProductVariant $variant): int
    {
        $pricing = $variant->relationLoaded('pricing') ? $variant->getRelation('pricing') : null;

        return (int) ($pricing['price'] ?? $variant->price_cop);
    }

    private function absolute(string $path): string
    {
        return preg_match('#^https?://#i', $path) ? $path : rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
