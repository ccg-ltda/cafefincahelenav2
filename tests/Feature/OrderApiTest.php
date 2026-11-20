<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variant = $this->makeVariant('FH-001', 30000);
    }

    private function makeVariant(string $sku, int $price, bool $published = true): ProductVariant
    {
        static $n = 0;
        $n++;
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => "Café $n", 'slug' => "cafe-$n", 'is_published' => true]);

        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'name' => "Café $n 250 g", 'price_cop' => $price, 'is_published' => $published]);
        $variant->forceFill(['stock_quantity' => 100])->save();

        return $variant;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'idempotency_key' => 'test-key-0123456789abcdef',
            'customer_name' => 'María González',
            'customer_email' => 'maria@example.com',
            'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca',
            'delivery_city' => 'Bogotá',
            'delivery_address' => 'Calle 45 # 12-34',
            'delivery_details' => 'Apto 201',
            'order_notes' => 'Llamar al llegar',
            'terms_accepted' => true,
            'data_consent' => true,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 2]],
        ], $override);
    }

    // ---- POST /api/orders/quote ----

    public function test_quote_with_valid_items_prices_from_the_database(): void
    {
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $this->variant->id, 'quantity' => 2]]])
            ->assertOk()
            ->assertJsonPath('data.subtotal_cop', 60000)
            ->assertJsonPath('data.shipping_cop', 0)
            ->assertJsonPath('data.shipping_pending', true)
            ->assertJsonPath('data.total_cop', 60000)
            ->assertJsonPath('data.unavailable', [])
            ->assertJsonPath('data.items.0.unit_price_cop', 30000);
    }

    public function test_quote_reports_an_unpublished_variant_as_unavailable(): void
    {
        $hidden = $this->makeVariant('FH-002', 50000, published: false);

        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $this->variant->id, 'quantity' => 1], ['variant_id' => $hidden->id, 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', [$hidden->id])
            ->assertJsonPath('data.total_cop', 30000)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_quote_reports_a_missing_product_as_unavailable(): void
    {
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => 999999, 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('data.unavailable', [999999])
            ->assertJsonPath('data.total_cop', 0);
    }

    public function test_quote_rejects_an_empty_cart(): void
    {
        $this->postJson('/api/orders/quote', ['items' => []])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    // ---- POST /api/orders ----

    public function test_valid_order_is_stored_with_database_prices_and_consent_timestamps(): void
    {
        $response = $this->postJson('/api/orders', $this->payload(['expected_total_cop' => 60000]))->assertCreated();

        $order = Order::firstOrFail();
        $response->assertJsonPath('order.reference', $order->reference)->assertJsonPath('order.total_cop', 60000);
        $this->assertSame('pending', $order->status);
        $this->assertSame(60000, $order->subtotal_cop);
        $this->assertSame(0, $order->shipping_cop);
        $this->assertSame('Cundinamarca', $order->delivery_department);
        $this->assertSame('Llamar al llegar', $order->order_notes);
        $this->assertSame('3001234567', $order->customer_phone);
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertNotNull($order->data_consent_at);
        $this->assertCount(1, $order->items);
    }

    public function test_phone_with_spaces_and_country_code_is_normalized(): void
    {
        $this->postJson('/api/orders', $this->payload(['customer_phone' => '+57 300 123 4567']))->assertCreated();

        $this->assertSame('3001234567', Order::firstOrFail()->customer_phone);
    }

    public function test_invalid_fields_are_rejected_with_field_errors(): void
    {
        $this->postJson('/api/orders', $this->payload([
            'customer_phone' => '2001234567',
            'customer_email' => 'no-es-un-correo',
            'terms_accepted' => false,
            'data_consent' => false,
            'delivery_department' => 'Atlantis',
        ]))->assertStatus(422)->assertJsonValidationErrors(['customer_phone', 'customer_email', 'terms_accepted', 'data_consent', 'delivery_department']);

        $this->assertSame(0, Order::count());
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->postJson('/api/orders', ['idempotency_key' => 'test-key-0123456789abcdef', 'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'customer_email', 'customer_phone', 'delivery_department', 'delivery_city', 'delivery_address', 'terms_accepted', 'data_consent']);
    }

    public function test_order_is_refused_when_the_reviewed_total_no_longer_matches(): void
    {
        $this->postJson('/api/orders', $this->payload(['expected_total_cop' => 1000]))->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::count());
    }

    public function test_order_is_refused_when_a_product_is_unavailable(): void
    {
        $hidden = $this->makeVariant('FH-003', 10000, published: false);

        $this->postJson('/api/orders', $this->payload(['items' => [['variant_id' => $hidden->id, 'quantity' => 1]]]))->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_resending_with_the_same_idempotency_key_does_not_duplicate_the_order(): void
    {
        $first = $this->postJson('/api/orders', $this->payload())->assertCreated();
        $second = $this->postJson('/api/orders', $this->payload())->assertOk();

        $this->assertSame($first->json('order.reference'), $second->json('order.reference'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Order::firstOrFail()->items()->count());
    }

    public function test_same_idempotency_key_with_another_email_conflicts(): void
    {
        $this->postJson('/api/orders', $this->payload())->assertCreated();

        $this->postJson('/api/orders', $this->payload(['customer_email' => 'otra@example.com']))->assertStatus(409);

        $this->assertSame(1, Order::count());
    }
}
