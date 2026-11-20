<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::create(['name' => 'Café', 'slug' => 'cafe', 'is_active' => true]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private static int $n = 0;

    /** @return array{0: Product, 1: ProductVariant} */
    private function product(array $variant = [], array $product = []): array
    {
        $n = ++self::$n;
        $p = Product::create(array_merge(['category_id' => $this->category->id, 'name' => "Café $n", 'slug' => "cafe-$n", 'is_published' => true], $product));
        $v = new ProductVariant(array_merge(['sku' => sprintf('FH-%03d', $n), 'name' => "Café $n 250 g", 'price_cop' => 30000, 'is_published' => true], $variant));
        $v->product()->associate($p);
        $v->forceFill(['stock_quantity' => $variant['stock_quantity'] ?? 0, 'low_stock_threshold' => $variant['low_stock_threshold'] ?? 5])->save();

        return [$p, $v];
    }

    private function stockUrl(Product $p, ProductVariant $v): string
    {
        return "/admin/products/{$p->id}/variants/{$v->id}/stock";
    }

    // ---- POST stock: exact stock, difference recorded ----

    private function setStock(Product $p, ProductVariant $v, int $to, ?int $expected = null, ?User $admin = null)
    {
        return $this->actingAs($admin ?? $this->admin())->postJson($this->stockUrl($p, $v), ['stock_quantity' => $to, 'expected_stock' => $expected ?? $v->fresh()->stock_quantity]);
    }

    public function test_raising_the_stock_records_the_positive_difference(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 3]);
        $admin = $this->admin();

        $this->setStock($p, $v, 10, null, $admin)->assertOk()
            ->assertJsonPath('movement.quantity', 7)->assertJsonPath('movement.from', 3)->assertJsonPath('movement.stock_after', 10)
            ->assertJsonPath('movement.origin', 'Admin')->assertJsonPath('data.stock_total', 10)->assertJsonPath('summary.total', 1);

        $this->assertSame(10, $v->fresh()->stock_quantity);
        $movement = InventoryMovement::firstOrFail();
        $this->assertSame(['ajuste', 7, 10, 'Ajuste desde listado', $admin->id], [$movement->type, $movement->quantity, $movement->stock_after, $movement->reason, $movement->user_id]);
    }

    public function test_lowering_the_stock_to_zero_records_the_negative_difference(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 10]);

        $this->setStock($p, $v, 4)->assertOk()->assertJsonPath('movement.quantity', -6);
        $this->setStock($p, $v, 0)->assertOk()->assertJsonPath('movement.quantity', -4)->assertJsonPath('data.stock_state', 'out');

        $this->assertSame(0, $v->fresh()->stock_quantity);
        $this->assertSame(2, InventoryMovement::count());
    }

    public function test_saving_the_same_stock_creates_no_movement(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 5]);

        $this->setStock($p, $v, 5)->assertOk()->assertJsonPath('movement', null);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_negative_or_non_integer_stock_is_rejected(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 5]);

        $this->setStock($p, $v, -1)->assertStatus(422)->assertJsonValidationErrors('stock_quantity');
        $this->actingAs($this->admin())->postJson($this->stockUrl($p, $v), ['stock_quantity' => 2.5, 'expected_stock' => 5])->assertStatus(422)->assertJsonValidationErrors('stock_quantity');
        $this->actingAs($this->admin())->postJson($this->stockUrl($p, $v), ['stock_quantity' => 3])->assertStatus(422)->assertJsonValidationErrors('expected_stock');

        $this->assertSame(5, $v->fresh()->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_the_modal_answers_409_when_the_stock_changed_meanwhile(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 10]);
        app(\App\Services\InventoryService::class)->adjust($v->id, 'venta', 4); // the modal still shows 10

        $this->setStock($p, $v, 50, 10)->assertStatus(409)->assertJsonPath('current_stock', 6)
            ->assertJsonPath('message', 'El stock cambió mientras editabas (ahora es 6). Revisa y vuelve a guardar.');

        $this->assertSame(6, $v->fresh()->stock_quantity);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_only_an_administrator_can_set_stock_or_read_movements(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 3]);
        $body = ['stock_quantity' => 9, 'expected_stock' => 3];

        $this->postJson($this->stockUrl($p, $v), $body)->assertUnauthorized();
        $this->getJson($this->stockUrl($p, $v).'-movements')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer)->postJson($this->stockUrl($p, $v), $body)->assertForbidden();
        $this->actingAs($customer)->getJson("/admin/products/{$p->id}/variants/{$v->id}/stock-movements")->assertForbidden();

        $this->assertSame(3, $v->fresh()->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_variant_of_another_product_is_not_found(): void
    {
        [$p] = $this->product();
        [, $other] = $this->product();

        $this->setStock($p, $other, 5, 0)->assertNotFound();
    }

    // ---- GET movements ----

    public function test_movements_endpoint_returns_the_last_ten_newest_first_with_from_to_user_and_origin(): void
    {
        [$p, $v] = $this->product();
        $admin = $this->admin();
        foreach (range(1, 11) as $i) {
            $this->setStock($p, $v, $i, null, $admin)->assertOk();
        }
        $order = Order::create(['reference' => 'CFH-TEST00000001', 'customer_name' => 'A', 'customer_email' => 'a@example.com', 'customer_phone' => '3001234567', 'delivery_address' => 'x', 'delivery_city' => 'y', 'subtotal_cop' => 1, 'total_cop' => 1]);
        app(\App\Services\InventoryService::class)->adjust($v->id, 'venta', 2, 'Venta CFH-TEST00000001', null, $order->id);

        $response = $this->actingAs($admin)->getJson("/admin/products/{$p->id}/variants/{$v->id}/stock-movements")->assertOk();
        $response->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.type', 'venta')->assertJsonPath('data.0.origin', 'Venta')->assertJsonPath('data.0.from', 11)->assertJsonPath('data.0.stock_after', 9)->assertJsonPath('data.0.quantity', -2)
            ->assertJsonPath('data.1.origin', 'Admin')->assertJsonPath('data.1.user_name', $admin->name)->assertJsonPath('data.1.from', 10)
            ->assertJsonPath('stock_quantity', 9);
    }

    // ---- GET /admin/products/data ----

    public function test_list_shows_price_range_stock_total_and_state_with_variants(): void
    {
        [$p, $a] = $this->product(['price_cop' => 30000, 'stock_quantity' => 2, 'low_stock_threshold' => 5]);
        $b = new ProductVariant(['sku' => 'FH-900', 'name' => 'Grande', 'price_cop' => 90000, 'is_published' => true]);
        $b->product()->associate($p);
        $b->forceFill(['stock_quantity' => 20])->save();

        $row = $this->actingAs($this->admin())->getJson('/admin/products/data')->assertOk()->json('data.0');

        $this->assertSame([30000, 90000, 22, 'low', 2], [$row['min_price_cop'], $row['max_price_cop'], $row['stock_total'], $row['stock_state'], count($row['variants'])]);
    }

    public function test_status_filters_and_summary(): void
    {
        $this->product(['stock_quantity' => 50], ['name' => 'Sano']);          // ok
        $this->product(['stock_quantity' => 2], ['name' => 'Poco']);           // low
        $this->product(['stock_quantity' => 0], ['name' => 'Vacío']);          // out
        $this->product(['stock_quantity' => 50], ['name' => 'Apagado', 'is_published' => false]);
        $admin = $this->admin();

        $names = fn (string $query) => collect($this->actingAs($admin)->getJson("/admin/products/data$query")->assertOk()->json('data'))->pluck('product_name')->sort()->values()->all();

        $this->assertSame(['Apagado'], $names('?status=inactive'));
        $this->assertSame(['Poco'], $names('?status=low'));
        $this->assertSame(['Vacío'], $names('?status=out'));
        $this->assertSame(['Poco', 'Sano', 'Vacío'], $names('?status=active'));
        $this->assertCount(4, $names(''));

        $this->actingAs($admin)->getJson('/admin/products/data')->assertJsonPath('summary', ['total' => 4, 'active' => 3, 'low' => 1, 'out' => 1]);
    }

    public function test_legacy_publication_parameter_is_an_alias_of_status(): void
    {
        $this->product([], ['name' => 'Encendido']);
        $this->product([], ['name' => 'Apagado', 'is_published' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/products/data?publication=published')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Encendido');
        $this->actingAs($admin)->getJson('/admin/products/data?publication=unpublished')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Apagado');
    }

    public function test_search_by_sku_or_name_and_category_filter(): void
    {
        [, $v] = $this->product(['sku' => 'ZZ-777'], ['name' => 'Especial']);
        $this->product([], ['name' => 'Otro']);
        $other = Category::create(['name' => 'Accesorios', 'slug' => 'accesorios', 'is_active' => true]);
        $this->product([], ['name' => 'Taza', 'category_id' => $other->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/products/data?search=ZZ-777')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Especial');
        $this->actingAs($admin)->getJson('/admin/products/data?search=otro')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/admin/products/data?category='.$other->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Taza');
    }

    public function test_list_is_paginated_in_pages_of_25(): void
    {
        foreach (range(1, 27) as $i) {
            $this->product();
        }
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/products/data')->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 27)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 25);
        $this->actingAs($admin)->getJson('/admin/products/data?page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.from', 26);
    }

    public function test_list_requires_an_administrator(): void
    {
        $this->getJson('/admin/products/data')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/admin/products/data')->assertForbidden();
    }

    // ---- Initial stock ----

    public function test_initial_stock_is_recorded_only_when_the_variant_is_created(): void
    {
        // The SKU generator uses MySQL's REGEXP, which SQLite (used by the tests) does not define.
        DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', fn ($pattern, $value) => preg_match('/'.$pattern.'/', (string) $value) ? 1 : 0, 2);
        [$p, $v] = $this->product();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/admin/products/{$p->id}/variants", ['name' => 'Nueva', 'price_cop' => 15000, 'is_published' => true, 'stock_quantity' => 7])->assertCreated();
        $created = ProductVariant::where('name', 'Nueva')->firstOrFail();
        $this->assertSame(7, $created->stock_quantity);
        $this->assertSame('Stock inicial', InventoryMovement::where('product_variant_id', $created->id)->firstOrFail()->reason);

        // Editing with the stock unchanged keeps it (see the editor tests below).
        $this->actingAs($admin)->putJson("/admin/products/{$p->id}/variants/{$created->id}", ['name' => 'Nueva', 'price_cop' => 16000, 'is_published' => true])->assertOk();
        $this->assertSame(7, $created->fresh()->stock_quantity);
        $this->assertSame(16000, $created->fresh()->price_cop);
    }

    // ---- Editing a variant: stock as an exact "ajuste" ----

    private function edit(Product $p, ProductVariant $v, array $override = [], ?User $admin = null)
    {
        return $this->actingAs($admin ?? $this->admin())->putJson("/admin/products/{$p->id}/variants/{$v->id}", array_merge([
            'name' => $v->name, 'price_cop' => $v->price_cop, 'is_published' => true,
            'stock_quantity' => $v->stock_quantity, 'expected_stock' => $v->stock_quantity,
        ], $override));
    }

    public function test_editing_with_a_new_stock_records_an_ajuste_with_the_difference(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 3]);
        $admin = $this->admin();

        $this->edit($p, $v, ['stock_quantity' => 12], $admin)->assertOk()->assertJsonPath('product.variants.0.stock_quantity', 12);

        $this->assertSame(12, $v->fresh()->stock_quantity);
        $movement = InventoryMovement::firstOrFail();
        $this->assertSame(['ajuste', 9, 12, 'Edición de producto', $admin->id], [$movement->type, $movement->quantity, $movement->stock_after, $movement->reason, $movement->user_id]);
    }

    public function test_editing_stock_down_records_a_negative_difference(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 10]);

        $this->edit($p, $v, ['stock_quantity' => 4])->assertOk();

        $movement = InventoryMovement::firstOrFail();
        $this->assertSame([-6, 4], [$movement->quantity, $movement->stock_after]);
    }

    public function test_editing_without_changing_the_stock_creates_no_movement(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 5]);

        $this->edit($p, $v, ['price_cop' => 45000])->assertOk();

        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(5, $v->fresh()->stock_quantity);
        $this->assertSame(45000, $v->fresh()->price_cop);
    }

    public function test_editing_without_stock_fields_leaves_the_stock_alone(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 5]);

        $this->actingAs($this->admin())->putJson("/admin/products/{$p->id}/variants/{$v->id}", ['name' => 'Otro nombre', 'price_cop' => 30000, 'is_published' => true])->assertOk();

        $this->assertSame(5, $v->fresh()->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_negative_or_invalid_stock_is_rejected(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 5]);

        $this->edit($p, $v, ['stock_quantity' => -1])->assertStatus(422)->assertJsonValidationErrors('stock_quantity');
        $this->edit($p, $v, ['stock_quantity' => 2.5])->assertStatus(422)->assertJsonValidationErrors('stock_quantity');
        $this->edit($p, $v, ['stock_quantity' => null])->assertStatus(422)->assertJsonValidationErrors('stock_quantity');

        $this->assertSame(5, $v->fresh()->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_stock_changed_meanwhile_answers_409_and_saves_nothing(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 10, 'price_cop' => 30000]);
        $admin = $this->admin();
        // The form opened with stock 10; a sale took 4 units before it was saved.
        app(\App\Services\InventoryService::class)->adjust($v->id, 'venta', 4);

        $this->edit($p, $v, ['stock_quantity' => 50, 'expected_stock' => 10, 'price_cop' => 99999], $admin)
            ->assertStatus(409)
            ->assertJsonPath('current_stock', 6)
            ->assertJsonPath('message', 'El stock cambió mientras editabas (ahora es 6). Revisa y vuelve a guardar.');

        $this->assertSame(6, $v->fresh()->stock_quantity);
        $this->assertSame(30000, $v->fresh()->price_cop);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_the_stock_changed_meanwhile_does_not_block_saving_other_fields(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 10]);
        app(\App\Services\InventoryService::class)->adjust($v->id, 'venta', 4);

        // The form did not touch the stock (new value == the stock it opened with): no conflict, the price is saved.
        $this->edit($p, $v, ['stock_quantity' => 10, 'expected_stock' => 10, 'price_cop' => 41000])->assertOk();

        $this->assertSame(6, $v->fresh()->stock_quantity);
        $this->assertSame(41000, $v->fresh()->price_cop);
    }

    public function test_the_low_stock_alert_keeps_working_with_the_default_threshold_of_5(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 5]);

        $this->assertSame(5, $v->fresh()->low_stock_threshold);
        $this->assertSame('low', app(\App\Services\InventoryService::class)->productState($p->fresh()->variants));
        $this->actingAs($this->admin())->getJson('/admin/products/data?status=low')->assertJsonCount(1, 'data');
    }

    public function test_the_detail_endpoint_returns_the_current_stock_of_each_variant(): void
    {
        [$p, $v] = $this->product(['stock_quantity' => 7]);

        $this->actingAs($this->admin())->getJson("/admin/products/{$p->id}/detail")->assertOk()
            ->assertJsonPath('product.variants.0.stock_quantity', 7)->assertJsonPath('product.variants.0.low_stock_threshold', 5);
    }
}
