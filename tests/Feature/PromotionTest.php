<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PromotionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function product(int $price = 34900, array $product = [], int $stock = 20): array
    {
        $n = ++self::$n;
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $p = Product::create(array_merge(['category_id' => $category->id, 'name' => "Café $n", 'slug' => "cafe-$n", 'is_published' => true], $product));

        return [$p, $this->variant($p, $price, $stock)];
    }

    private function variant(Product $product, int $price, int $stock = 20, bool $published = true): ProductVariant
    {
        $n = ++self::$n;
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => sprintf('FH-%03d', $n), 'name' => "Bolsa $n", 'price_cop' => $price, 'is_published' => $published]);
        $variant->forceFill(['stock_quantity' => $stock])->save();

        return $variant;
    }

    private function promotion(Product $product, array $variants, array $attributes = []): Promotion
    {
        $promotion = Promotion::create(array_merge(['product_id' => $product->id, 'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true], $attributes));
        $promotion->variants()->sync(array_map(fn ($variant) => $variant->id, $variants));

        return $promotion;
    }

    private function form(Product $product, array $variants, array $override = []): array
    {
        return array_merge([
            'product_id' => $product->id, 'variant_ids' => array_map(fn ($variant) => $variant->id, $variants),
            'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true, 'starts_at' => null, 'ends_at' => null,
        ], $override);
    }

    private function publicVariant(ProductVariant $variant): array
    {
        $variants = collect($this->getJson('/api/products')->assertOk()->json('data'))->pluck('variants')->flatten(1)->keyBy('id');

        return $variants[$variant->id];
    }

    private function orderBody(ProductVariant $variant, int $quantity = 1, array $override = []): array
    {
        return array_merge([
            'idempotency_key' => 'promo-key-0123456789abcdef',
            'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34',
            'terms_accepted' => true, 'data_consent' => true,
            'items' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
        ], $override);
    }

    // ---- Calculation ----

    public function test_percentage_and_fixed_prices_are_rounded_to_the_nearest_hundred(): void
    {
        $service = app(PromotionService::class);

        $this->assertSame(27900, $service->priceFor(34900, 'percentage', 20)); // 27920
        $this->assertSame(18000, $service->priceFor(19950, 'percentage', 10)); // 17955
        $this->assertSame(29900, $service->priceFor(34900, 'fixed_amount', 5000));
        $this->assertSame(29900, $service->priceFor(34900, 'fixed_amount', 4960)); // 29940
        $this->assertSame('-20%', $service->label('percentage', 20));
        $this->assertSame('-$5.000', $service->label('fixed_amount', 5000));
    }

    // ---- Which promotions apply ----

    public function test_a_current_promotion_changes_the_public_price_and_exposes_the_original(): void
    {
        [$p, $v] = $this->product(34900);
        $ends = now()->addDays(10)->startOfSecond();
        $this->promotion($p, [$v], ['ends_at' => $ends]);

        $api = $this->publicVariant($v);

        $this->assertSame([27900, 34900, '-20%'], [$api['price_cop'], $api['original_price_cop'], $api['discount_label']]);
        $this->assertSame($ends->toIso8601String(), Carbon::parse($api['promotion_ends_at'])->toIso8601String());
    }

    public function test_scheduled_expired_and_inactive_promotions_do_not_apply(): void
    {
        [$p, $v] = $this->product(34900);
        $scheduled = $this->promotion($p, [$v], ['starts_at' => now()->addDay()]);
        $this->assertArrayNotHasKey('original_price_cop', $this->publicVariant($v));
        $this->assertSame(34900, $this->publicVariant($v)['price_cop']);

        $scheduled->update(['starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]); // expired
        $this->assertArrayNotHasKey('original_price_cop', $this->publicVariant($v));

        $scheduled->update(['starts_at' => null, 'ends_at' => null, 'is_active' => false]); // inactive
        $this->assertArrayNotHasKey('original_price_cop', $this->publicVariant($v));
        $this->assertFalse(app(PromotionService::class)->hasCurrent());
    }

    public function test_a_promotion_of_an_inactive_product_does_not_apply_and_the_admin_sees_a_note(): void
    {
        [$p, $v] = $this->product(34900);
        $promotion = $this->promotion($p, [$v]);
        $this->assertTrue(app(PromotionService::class)->hasCurrent());

        $p->update(['is_published' => false]);

        $this->assertFalse(app(PromotionService::class)->hasCurrent());
        $this->actingAs($this->admin())->getJson('/admin/promotions/data')->assertOk()
            ->assertJsonPath('data.0.status', 'current')->assertJsonPath('data.0.note', 'product_inactive')->assertJsonPath('data.0.id', $promotion->id);
    }

    public function test_a_promoted_variant_without_stock_stays_listed_as_sold_out(): void
    {
        [$p, $v] = $this->product(34900, [], 0);
        $this->promotion($p, [$v]);

        $api = $this->publicVariant($v);

        $this->assertFalse($api['in_stock']);
        $this->assertSame(34900, $api['original_price_cop']);
    }

    // ---- The "Promociones" tab ----

    public function test_the_promotions_tab_flag_appears_and_disappears_with_current_promotions(): void
    {
        [$p, $v] = $this->product(34900);
        $this->getJson('/api/products')->assertJsonPath('meta.has_promotions', false);

        $promotion = $this->promotion($p, [$v], ['ends_at' => now()->addDay()]);
        $this->getJson('/api/products')->assertJsonPath('meta.has_promotions', true);
        $this->getJson('/api/categories')->assertJsonPath('meta.has_promotions', true);

        $this->travel(2)->days(); // it expired
        $this->getJson('/api/products')->assertJsonPath('meta.has_promotions', false);
        $this->getJson('/api/categories')->assertJsonPath('meta.has_promotions', false);

        $this->travelBack();
        $promotion->update(['is_active' => false]);
        $this->getJson('/api/products')->assertJsonPath('meta.has_promotions', false);
    }

    public function test_promociones_page_answers_200_and_is_noindex_only_without_promotions(): void
    {
        $this->get('/promociones')->assertOk()->assertSee('noindex,nofollow', false);

        [$p, $v] = $this->product(34900);
        $this->promotion($p, [$v]);

        $this->get('/promociones')->assertOk()->assertDontSee('noindex', false)->assertSee('Promociones | Café Finca Helena', false);
    }

    public function test_the_sitemap_lists_promociones_only_while_a_promotion_is_current(): void
    {
        [$p, $v] = $this->product(34900);
        $promotion = $this->promotion($p, [$v], ['is_active' => false]);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/promociones');

        $promotion->update(['is_active' => true]);
        $this->get('/sitemap.xml')->assertOk()->assertSee('/promociones');
    }

    // ---- Validation ----

    public function test_a_discount_larger_than_the_price_or_out_of_range_answers_422(): void
    {
        [$p, $v] = $this->product(10000);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['discount_type' => 'fixed_amount', 'discount_value' => 12000]))
            ->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $this->assertStringContainsString('El descuento supera el precio', $response->json('errors.discount_value.0'));

        $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['discount_value' => 91]))->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['discount_value' => 0]))->assertStatus(422)->assertJsonValidationErrors('discount_value');
        // A discount so small that the rounded price does not change.
        $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['discount_type' => 'fixed_amount', 'discount_value' => 30]))->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $this->assertSame(0, Promotion::count());
    }

    public function test_one_bad_variant_blocks_a_fixed_discount_and_names_it(): void
    {
        [$p, $cheap] = $this->product(4000);
        $big = $this->variant($p, 50000);

        $response = $this->actingAs($this->admin())->postJson('/admin/promotions', $this->form($p, [$cheap, $big], ['discount_type' => 'fixed_amount', 'discount_value' => 5000]))->assertStatus(422);

        $this->assertStringContainsString($cheap->name, $response->json('errors.discount_value.0'));
    }

    public function test_the_end_date_must_be_after_the_start_date(): void
    {
        [$p, $v] = $this->product();

        $this->actingAs($this->admin())->postJson('/admin/promotions', $this->form($p, [$v], ['starts_at' => '2026-12-10T08:00', 'ends_at' => '2026-12-01T08:00']))
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_overlapping_active_promotions_on_a_variant_answer_422(): void
    {
        [$p, $v] = $this->product();
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['starts_at' => '2026-12-01T00:00', 'ends_at' => '2026-12-20T00:00']))->assertCreated();

        // Crosses the first one.
        $response = $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['starts_at' => '2026-12-15T00:00', 'ends_at' => '2026-12-30T00:00']))
            ->assertStatus(422)->assertJsonValidationErrors('variant_ids');
        $this->assertStringContainsString($v->name, $response->json('errors.variant_ids.0'));
        // Open-ended also crosses it.
        $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v]))->assertStatus(422)->assertJsonValidationErrors('variant_ids');
        // Starting exactly when the first one ends does not.
        $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['starts_at' => '2026-12-20T00:00', 'ends_at' => '2026-12-31T00:00']))->assertCreated();
        // An inactive one never conflicts, but activating it later does.
        $inactive = $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$v], ['is_active' => false, 'starts_at' => '2026-12-05T00:00', 'ends_at' => '2026-12-06T00:00']))->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson("/admin/promotions/$inactive/status", ['is_active' => true])->assertStatus(422)->assertJsonValidationErrors('variant_ids');
    }

    public function test_dates_typed_in_colombian_time_are_stored_in_utc(): void
    {
        [$p, $v] = $this->product();

        $id = $this->actingAs($this->admin())->postJson('/admin/promotions', $this->form($p, [$v], ['starts_at' => '2026-12-01T08:00', 'ends_at' => '2026-12-05T23:30']))
            ->assertCreated()->assertJsonPath('data.starts_at_local', '2026-12-01T08:00')->json('data.id');

        $promotion = Promotion::findOrFail($id);
        $this->assertSame('2026-12-01 13:00:00', $promotion->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-06 04:30:00', $promotion->ends_at->utc()->format('Y-m-d H:i:s'));
    }

    // ---- Checkout ----

    public function test_store_saves_the_promotional_price_and_the_original(): void
    {
        [$p, $v] = $this->product(34900);
        $promotion = $this->promotion($p, [$v]);

        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $v->id, 'quantity' => 2]]])->assertOk()
            ->assertJsonPath('data.items.0.unit_price_cop', 27900)->assertJsonPath('data.items.0.original_unit_price_cop', 34900)
            ->assertJsonPath('data.savings_cop', 14000)->assertJsonPath('data.total_cop', 55800);

        $this->postJson('/api/orders', $this->orderBody($v, 2, ['expected_total_cop' => 55800]))->assertCreated()->assertJsonPath('order.total_cop', 55800);

        $item = OrderItem::firstOrFail();
        $this->assertSame([27900, 34900, $promotion->id, 55800], [$item->unit_price_cop, $item->original_unit_price_cop, $item->promotion_id, Order::firstOrFail()->total_cop]);
    }

    public function test_an_order_without_promotion_stores_no_original_price(): void
    {
        [, $v] = $this->product(34900);

        $this->postJson('/api/orders', $this->orderBody($v))->assertCreated();

        $item = OrderItem::firstOrFail();
        $this->assertNull($item->original_unit_price_cop);
        $this->assertNull($item->promotion_id);
    }

    public function test_a_promotion_that_ends_between_quote_and_store_is_a_price_change(): void
    {
        [$p, $v] = $this->product(34900);
        $this->promotion($p, [$v], ['ends_at' => now()->addHour()]);

        $quoted = $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $v->id, 'quantity' => 1]]])->assertOk()->json('data.total_cop');
        $this->assertSame(27900, $quoted);

        $this->travel(2)->hours(); // the promotion is over

        $this->postJson('/api/orders', $this->orderBody($v, 1, ['expected_total_cop' => $quoted]))->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::count());
        $this->assertSame(20, $v->fresh()->stock_quantity);
        // A fresh quote now shows the base price: the storefront flags it as a price change.
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $v->id, 'quantity' => 1]]])->assertJsonPath('data.items.0.unit_price_cop', 34900);
    }

    // ---- Admin ----

    public function test_only_an_administrator_manages_promotions(): void
    {
        [$p, $v] = $this->product();
        $promotion = $this->promotion($p, [$v]);
        $customer = User::factory()->create();

        $this->getJson('/admin/promotions/data')->assertUnauthorized();
        $this->postJson('/admin/promotions', $this->form($p, [$v]))->assertUnauthorized();
        $this->deleteJson("/admin/promotions/{$promotion->id}")->assertUnauthorized();

        $this->actingAs($customer)->getJson('/admin/promotions/data')->assertForbidden();
        $this->actingAs($customer)->postJson('/admin/promotions', $this->form($p, [$v]))->assertForbidden();
        $this->actingAs($customer)->patchJson("/admin/promotions/{$promotion->id}/status", ['is_active' => false])->assertForbidden();
        $this->actingAs($customer)->deleteJson("/admin/promotions/{$promotion->id}")->assertForbidden();

        $this->assertTrue(Promotion::whereKey($promotion->id)->exists());
    }

    public function test_the_list_filters_by_status_and_search_and_returns_the_summary(): void
    {
        [$a, $va] = $this->product(34900, ['name' => 'Café Premium']);
        [$b, $vb] = $this->product(20000, ['name' => 'Panela']);
        [$c, $vc] = $this->product(20000, ['name' => 'Otro']);
        [$d, $vd] = $this->product(20000, ['name' => 'Cuarto']);
        $this->promotion($a, [$va]);
        $this->promotion($b, [$vb], ['starts_at' => now()->addDay()]);
        $this->promotion($c, [$vc], ['ends_at' => now()->subDay(), 'starts_at' => now()->subDays(3)]);
        $this->promotion($d, [$vd], ['is_active' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/promotions/data')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('summary', ['current' => 1, 'scheduled' => 1, 'expired' => 1, 'inactive' => 1]);
        foreach (['current' => 'Café Premium', 'scheduled' => 'Panela', 'expired' => 'Otro', 'inactive' => 'Cuarto'] as $status => $name) {
            $this->actingAs($admin)->getJson("/admin/promotions/data?status=$status")->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.name', $name);
        }
        $this->actingAs($admin)->getJson('/admin/promotions/data?search=prem')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/admin/promotions/data')->assertJsonPath('data.3.promo_min_cop', 27900)->assertJsonPath('data.3.base_min_cop', 34900);
    }

    public function test_deleting_a_promotion_used_in_orders_answers_409(): void
    {
        [$p, $v] = $this->product(34900);
        $promotion = $this->promotion($p, [$v]);
        $this->postJson('/api/orders', $this->orderBody($v))->assertCreated();

        $this->actingAs($this->admin())->deleteJson("/admin/promotions/{$promotion->id}")
            ->assertStatus(409)->assertJsonPath('message', 'Esta promoción ya se usó en pedidos; puedes desactivarla.');

        $this->assertTrue(Promotion::whereKey($promotion->id)->exists());
        $this->actingAs($this->admin())->getJson('/admin/promotions/data')->assertJsonPath('data.0.used_in_orders', true);
        // It can be deactivated.
        $this->actingAs($this->admin())->patchJson("/admin/promotions/{$promotion->id}/status", ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_an_unused_promotion_is_really_deleted(): void
    {
        [$p, $v] = $this->product(34900);
        $promotion = $this->promotion($p, [$v]);

        $this->actingAs($this->admin())->deleteJson("/admin/promotions/{$promotion->id}")->assertOk()->assertJsonPath('summary.current', 0);

        $this->assertFalse(Promotion::whereKey($promotion->id)->exists());
        $this->assertSame(0, \DB::table('promotion_variant')->count());
        $this->assertArrayNotHasKey('original_price_cop', $this->publicVariant($v));
    }

    public function test_editing_a_promotion_updates_its_variants_and_values(): void
    {
        [$p, $a] = $this->product(34900);
        $b = $this->variant($p, 20000);
        $admin = $this->admin();
        $id = $this->actingAs($admin)->postJson('/admin/promotions', $this->form($p, [$a]))->assertCreated()->json('data.id');

        $this->actingAs($admin)->putJson("/admin/promotions/$id", $this->form($p, [$a, $b], ['discount_type' => 'fixed_amount', 'discount_value' => 5000]))
            ->assertOk()->assertJsonPath('data.discount_label', '-$5.000')->assertJsonCount(2, 'data.variants');

        $this->assertSame(29900, $this->publicVariant($a)['price_cop']);
        $this->assertSame(15000, $this->publicVariant($b)['price_cop']);
        $this->actingAs($admin)->getJson("/admin/promotions/$id")->assertOk()->assertJsonPath('data.variant_ids', [$a->id, $b->id]);
    }
}
