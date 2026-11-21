<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The initial catalogue is real data for production: it must only create what is missing and never overwrite edits. */
class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = ['cafe-premium', 'combo-familiar', 'kit-degustacion', 'pack-grano-premium'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function seedCatalog(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    }

    /** @return array<string, mixed> everything a second run could wrongly change */
    private function snapshot(): array
    {
        return [
            'categories' => Category::orderBy('id')->get()->toArray(),
            'products' => Product::orderBy('id')->get()->toArray(),
            'variants' => ProductVariant::orderBy('id')->get()->toArray(),
        ];
    }

    public function test_an_empty_database_gets_the_real_catalogue(): void
    {
        $this->seedCatalog();

        $this->assertSame(1, Category::count());
        $this->assertSame(self::SLUGS, Product::orderBy('slug')->pluck('slug')->all()); // the final short slugs
        $this->assertSame(13, ProductVariant::count());
        $this->assertSame(array_map(fn ($n) => sprintf('FH-%03d', $n), range(1, 13)), ProductVariant::orderBy('sku')->pluck('sku')->all());
        $this->assertSame(19900, ProductVariant::where('sku', 'FH-001')->value('price_cop'));
        $this->assertSame(13 * 100, (int) ProductVariant::sum('stock_quantity')); // outside production every variant starts with 100
        $this->assertSame(13, InventoryMovement::count()); // through InventoryService: one movement each
        $this->assertSame(0, Product::where('is_published', false)->count() + ProductVariant::where('is_published', false)->count());
        $this->assertSame(10, ProductVariant::where('product_id', Product::where('slug', 'cafe-premium')->value('id'))->count());
        foreach (ProductVariant::pluck('image_path')->unique() as $path) {
            $this->assertTrue(Storage::disk('public')->exists($path), "missing image $path");
        }
        $this->assertCount(10, Storage::disk('public')->files('products'));
    }

    public function test_running_it_twice_changes_nothing_and_creates_no_duplicates(): void
    {
        $this->seedCatalog();
        $first = $this->snapshot();

        $this->seedCatalog();

        $this->assertSame($first, $this->snapshot());
    }

    public function test_production_does_not_invent_stock(): void
    {
        $this->app['env'] = 'production';

        $this->seedCatalog();

        $this->assertSame(0, (int) ProductVariant::sum('stock_quantity')); // "Agotado" until the admin loads the real quantities
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_it_fills_only_empty_untouched_stock_on_rerun(): void
    {
        $this->seedCatalog();
        $sold = ProductVariant::where('sku', 'FH-002')->first();
        app(InventoryService::class)->adjust($sold->id, 'ajuste', 0, 'Vendido todo'); // 0 but with history

        $this->seedCatalog();

        $this->assertSame(0, (int) $sold->fresh()->stock_quantity); // an admin's 0 with history is respected
    }

    public function test_it_never_overwrites_what_the_admin_edited(): void
    {
        $this->seedCatalog();

        $category = Category::where('slug', 'cafe')->first();
        $category->update(['name' => 'Cafés de la finca', 'is_active' => false]);
        $product = Product::where('slug', 'kit-degustacion')->first();
        $product->update(['name' => 'Kit regalo', 'description' => 'Texto nuevo', 'short_description' => 'Corto', 'is_published' => false, 'sort_order' => 9]);
        $variant = ProductVariant::where('sku', 'FH-001')->first();
        $variant->update(['name' => 'Otro nombre', 'price_cop' => 21000, 'is_published' => false, 'image_path' => 'products/propia.jpg']);
        $variant->forceFill(['stock_quantity' => 17])->save(); // stock only changes through InventoryService
        Storage::disk('public')->put('products/cafe-125-m.webp', 'imagen cambiada por el admin');
        $before = $this->snapshot();

        $this->seedCatalog();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame([21000, 17, 0, 'products/propia.jpg'], [$variant->fresh()->price_cop, $variant->fresh()->stock_quantity, (int) $variant->fresh()->is_published, $variant->fresh()->image_path]);
        $this->assertSame(['Cafés de la finca', 0], [$category->fresh()->name, (int) $category->fresh()->is_active]);
        $this->assertSame(['Kit regalo', 0], [$product->fresh()->name, (int) $product->fresh()->is_published]);
        $this->assertSame('imagen cambiada por el admin', Storage::disk('public')->get('products/cafe-125-m.webp')); // an existing file is never replaced
    }

    public function test_a_missing_image_file_is_restored_without_touching_the_rest(): void
    {
        $this->seedCatalog();
        Storage::disk('public')->delete('products/cafe-500-t.webp');
        $before = $this->snapshot();

        $this->seedCatalog();

        $this->assertTrue(Storage::disk('public')->exists('products/cafe-500-t.webp'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_database_that_already_has_the_short_slugs_and_their_redirects_gets_no_duplicates(): void
    {
        $this->seedCatalog();
        // What production looks like after the slug migration ran: short slugs plus the redirect rows of the old ones.
        $redirects = ['kit-degustacion-2-125-g' => 'kit-degustacion', 'combo-familiar-250-125-g' => 'combo-familiar', 'pack-grano-premium-500-g' => 'pack-grano-premium'];
        foreach ($redirects as $old => $new) {
            DB::table('product_slug_redirects')->insert(['slug' => $old, 'product_id' => Product::where('slug', $new)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = $this->snapshot();

        $this->seedCatalog();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(4, Product::count());
        $this->assertSame(0, Product::whereIn('slug', array_keys($redirects))->count()); // an old slug is never reborn as a product
    }

    public function test_a_database_seeded_with_the_old_long_slugs_keeps_its_products_and_variants(): void
    {
        $category = Category::create(['name' => 'Café', 'slug' => 'cafe', 'is_active' => true]);
        $old = Product::create(['category_id' => $category->id, 'name' => 'Kit Degustación — 2 x 125 gr', 'slug' => 'kit-degustacion-2-125-g', 'is_published' => true, 'sort_order' => 1]);
        $variant = ProductVariant::create(['product_id' => $old->id, 'sku' => 'FH-011', 'name' => 'Kit Degustación 2 × 125 g Molido', 'weight_grams' => 250, 'grind_type' => 'Molido', 'price_cop' => 30000, 'image_path' => 'products/cafe-125-m.webp', 'is_published' => true]);

        $this->seedCatalog();

        $this->assertSame(4, Product::count());
        $this->assertSame(13, ProductVariant::count());
        $this->assertSame([$old->id, 30000], [$variant->fresh()->product_id, $variant->fresh()->price_cop]); // found by SKU: not duplicated, not moved, not repriced
        $this->assertSame(0, Product::where('slug', 'kit-degustacion')->count());
    }

    public function test_the_catalogue_is_visible_in_the_public_api_after_seeding(): void
    {
        $this->seedCatalog();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(4, 'data');
    }
}
