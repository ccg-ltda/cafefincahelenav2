<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Public catalogue stock and the checkout's stock handling. */
class StoreInventoryTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function variant(int $stock, bool $published = true, ?Product $product = null): ProductVariant
    {
        $n = ++self::$n;
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product ??= Product::create(['category_id' => $category->id, 'name' => "Café $n", 'slug' => "cafe-$n", 'is_published' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => sprintf('FH-%03d', $n), 'name' => "Bolsa $n", 'price_cop' => 20000, 'is_published' => $published]);
        $variant->forceFill(['stock_quantity' => $stock])->save();

        return $variant;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'idempotency_key' => 'stock-key-0123456789abcdef',
            'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34',
            'terms_accepted' => true, 'data_consent' => true,
        ], $override);
    }

    /** @param array<int, array{0: ProductVariant, 1: int}> $items */
    private function lines(array $items): array
    {
        return array_map(fn ($line) => ['variant_id' => $line[0]->id, 'quantity' => $line[1]], $items);
    }

    private function order(array $items, array $override = []): array
    {
        return $this->payload($override) + ['items' => $this->lines($items)];
    }

    // ---- Public API ----

    public function test_public_api_returns_in_stock_and_available_quantity_per_variant(): void
    {
        $withStock = $this->variant(7);
        $soldOut = $this->variant(0);

        $variants = collect($this->getJson('/api/products')->assertOk()->json('data'))->pluck('variants')->flatten(1)->keyBy('id');

        $this->assertTrue($variants[$withStock->id]['in_stock']);
        $this->assertSame(7, $variants[$withStock->id]['available_quantity']);
        // A sold-out variant stays listed.
        $this->assertFalse($variants[$soldOut->id]['in_stock']);
        $this->assertSame(0, $variants[$soldOut->id]['available_quantity']);

        $slug = Product::findOrFail($withStock->product_id)->slug;
        $this->getJson("/api/products/$slug")->assertOk()->assertJsonPath('data.variants.0.in_stock', true)->assertJsonPath('data.variants.0.available_quantity', 7);
    }

    // ---- Quote ----

    public function test_quote_flags_a_line_larger_than_the_available_stock(): void
    {
        $few = $this->variant(2);
        $plenty = $this->variant(50);

        $this->postJson('/api/orders/quote', ['items' => $this->lines([[$few, 5], [$plenty, 3]])])->assertOk()
            ->assertJsonPath('data.items.0.available_quantity', 2)->assertJsonPath('data.items.0.insufficient', true)
            ->assertJsonPath('data.items.1.insufficient', false);
    }

    // ---- Store ----

    public function test_store_deducts_the_stock_and_records_the_sale_with_the_order(): void
    {
        $a = $this->variant(10);
        $b = $this->variant(4);

        $reference = $this->postJson('/api/orders', $this->order([[$a, 3], [$b, 4]]))->assertCreated()->json('order.reference');

        $this->assertSame(7, $a->fresh()->stock_quantity);
        $this->assertSame(0, $b->fresh()->stock_quantity);
        $order = Order::where('reference', $reference)->firstOrFail();
        $sale = InventoryMovement::where('product_variant_id', $a->id)->firstOrFail();
        $this->assertSame(['venta', -3, 7, "Venta $reference", $order->id, null], [$sale->type, $sale->quantity, $sale->stock_after, $sale->reason, $sale->order_id, $sale->user_id]);
        $this->assertSame(2, InventoryMovement::where('order_id', $order->id)->count());
    }

    public function test_store_with_not_enough_stock_answers_422_and_creates_nothing(): void
    {
        $ok = $this->variant(10);
        $short = $this->variant(2);

        $response = $this->postJson('/api/orders', $this->order([[$ok, 3], [$short, 5]]))->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertStringContainsString('Solo quedan 2', $response->json('errors.items.0'));
        $this->assertStringContainsString($short->name, $response->json('errors.items.0'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(10, $ok->fresh()->stock_quantity);
        $this->assertSame(2, $short->fresh()->stock_quantity);
    }

    public function test_a_sold_out_variant_cannot_be_bought(): void
    {
        $soldOut = $this->variant(0);

        $response = $this->postJson('/api/orders', $this->order([[$soldOut, 1]]))->assertStatus(422);

        $this->assertStringContainsString('agotado', $response->json('errors.items.0'));
        $this->assertSame(0, Order::count());
    }

    public function test_the_last_unit_goes_to_the_first_buyer_only(): void
    {
        $last = $this->variant(1);

        $this->postJson('/api/orders', $this->order([[$last, 1]], ['idempotency_key' => 'buyer-one-0123456789abcdef']))->assertCreated();
        $this->postJson('/api/orders', $this->order([[$last, 1]], ['idempotency_key' => 'buyer-two-0123456789abcdef', 'customer_email' => 'otro@example.com']))->assertStatus(422);

        $this->assertSame(0, $last->fresh()->stock_quantity);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_the_same_idempotency_key_does_not_deduct_twice(): void
    {
        $variant = $this->variant(10);
        $body = $this->order([[$variant, 3]]);

        $first = $this->postJson('/api/orders', $body)->assertCreated();
        $second = $this->postJson('/api/orders', $body)->assertOk();

        $this->assertSame($first->json('order.reference'), $second->json('order.reference'));
        $this->assertSame(7, $variant->fresh()->stock_quantity);
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, Order::count());
    }

    // ---- Returning an order ----

    public function test_returning_an_order_puts_its_stock_back_once(): void
    {
        $a = $this->variant(10);
        $b = $this->variant(5);
        $reference = $this->postJson('/api/orders', $this->order([[$a, 3], [$b, 2]]))->assertCreated()->json('order.reference');
        $order = Order::where('reference', $reference)->firstOrFail();
        $inventory = app(InventoryService::class);

        $this->assertSame(5, $inventory->returnOrder($order));
        $this->assertSame([10, 5], [$a->fresh()->stock_quantity, $b->fresh()->stock_quantity]);
        $movement = InventoryMovement::where('order_id', $order->id)->where('type', 'devolucion')->where('product_variant_id', $a->id)->firstOrFail();
        $this->assertSame([3, "Devolución $reference"], [$movement->quantity, $movement->reason]);

        // A second return changes nothing.
        $this->assertSame(0, $inventory->returnOrder($order));
        $this->assertSame([10, 5], [$a->fresh()->stock_quantity, $b->fresh()->stock_quantity]);
    }

    public function test_returning_an_order_that_never_took_stock_out_changes_nothing(): void
    {
        $variant = $this->variant(4);
        $order = Order::create(['reference' => 'CFH-OLD000000001', 'customer_name' => 'A', 'customer_email' => 'a@example.com', 'customer_phone' => '3001234567', 'delivery_address' => 'x', 'delivery_city' => 'y', 'subtotal_cop' => 1, 'total_cop' => 1]);
        $order->items()->create(['product_variant_id' => $variant->id, 'sku' => $variant->sku, 'name' => $variant->name, 'quantity' => 2, 'unit_price_cop' => 1, 'subtotal_cop' => 2]);

        $this->assertSame(0, app(InventoryService::class)->returnOrder($order));
        $this->assertSame(4, $variant->fresh()->stock_quantity);
    }
}
