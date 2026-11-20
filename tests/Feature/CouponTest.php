<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variant = $this->makeVariant('FH-001', 50000);
    }

    private function makeVariant(string $sku, int $price): ProductVariant
    {
        static $n = 0;
        $n++;
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => "Café $n", 'slug' => "cafe-$n", 'is_published' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'name' => "Café $n 250 g", 'price_cop' => $price, 'is_published' => true]);
        $variant->forceFill(['stock_quantity' => 100])->save();

        return $variant;
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + ['code' => 'HELENA10', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
    }

    private function promote(ProductVariant $variant, int $percent = 20): void
    {
        $promotion = Promotion::create(['product_id' => $variant->product_id, 'discount_type' => 'percentage', 'discount_value' => $percent, 'is_active' => true]);
        $promotion->variants()->sync([$variant->id]);
    }

    private function quote(?string $code, int $quantity = 2, ?string $email = null, ?ProductVariant $variant = null)
    {
        return $this->postJson('/api/orders/quote', ['items' => [['variant_id' => ($variant ?? $this->variant)->id, 'quantity' => $quantity]], 'coupon_code' => $code, 'customer_email' => $email]);
    }

    private function order(array $override = [])
    {
        return $this->postJson('/api/orders', array_merge([
            'idempotency_key' => 'test-key-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com',
            'customer_phone' => '3001234567', 'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34',
            'terms_accepted' => true, 'data_consent' => true, 'items' => [['variant_id' => $this->variant->id, 'quantity' => 2]],
        ], $override));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    // ---- Discount rules (quote) ----

    public function test_percentage_discounts_the_eligible_subtotal(): void
    {
        $this->coupon();

        $this->quote('HELENA10')->assertOk()
            ->assertJsonPath('data.subtotal_cop', 100000)->assertJsonPath('data.discount_cop', 10000)->assertJsonPath('data.total_cop', 90000)
            ->assertJsonPath('data.coupon.code', 'HELENA10')->assertJsonPath('data.coupon_error', null);
    }

    public function test_percentage_respects_the_discount_cap(): void
    {
        $this->coupon(['discount_value' => 50, 'max_discount_cop' => 20000]);

        $this->quote('HELENA10')->assertJsonPath('data.discount_cop', 20000)->assertJsonPath('data.total_cop', 80000);
    }

    public function test_fixed_amount_never_exceeds_the_subtotal(): void
    {
        $this->coupon(['discount_type' => 'fixed_amount', 'discount_value' => 500000]);

        $this->quote('HELENA10', 1)->assertJsonPath('data.discount_cop', 50000)->assertJsonPath('data.total_cop', 0);
    }

    public function test_fixed_amount_below_the_subtotal(): void
    {
        $this->coupon(['discount_type' => 'fixed_amount', 'discount_value' => 5000]);

        $this->quote('HELENA10')->assertJsonPath('data.discount_cop', 5000)->assertJsonPath('data.total_cop', 95000);
    }

    public function test_discount_is_rounded_to_the_hundred(): void
    {
        $this->coupon(['discount_value' => 15]);
        $odd = $this->makeVariant('FH-ODD', 34900);

        $this->quote('HELENA10', 1, null, $odd)->assertJsonPath('data.discount_cop', 5200); // 5235 -> 5200
    }

    public function test_code_is_case_insensitive_and_trimmed(): void
    {
        $this->coupon();

        $this->quote('  helena10 ')->assertJsonPath('data.discount_cop', 10000);
    }

    public function test_minimum_purchase(): void
    {
        $this->coupon(['min_subtotal_cop' => 150000]);

        $this->quote('HELENA10')->assertOk()->assertJsonPath('data.discount_cop', 0)->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.coupon_error.message', 'Compra mínima de $ 150.000')->assertJsonPath('data.total_cop', 100000);
        $this->quote('HELENA10', 3)->assertJsonPath('data.discount_cop', 15000);
    }

    public function test_unknown_inactive_expired_and_scheduled_coupons_share_the_generic_message(): void
    {
        $this->coupon(['code' => 'INACTIVO', 'is_active' => false]);
        $this->coupon(['code' => 'VENCIDO', 'ends_at' => now()->subDay()]);
        $this->coupon(['code' => 'FUTURO', 'starts_at' => now()->addDay()]);

        foreach (['NOEXISTE', 'INACTIVO', 'VENCIDO', 'FUTURO', 'a b!'] as $code) {
            $this->quote($code)->assertOk()->assertJsonPath('data.coupon_error.message', 'Cupón no válido o vencido')->assertJsonPath('data.discount_cop', 0);
        }
    }

    public function test_exhausted_coupon_gives_a_concrete_reason(): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        $this->order(['coupon_code' => 'HELENA10'])->assertCreated();

        $this->quote('HELENA10', 2, 'otra@example.com')->assertJsonPath('data.coupon_error.message', 'Este cupón ya alcanzó su límite de usos');
        $this->assertSame('exhausted', app(CouponService::class)->status($coupon->fresh()));
    }

    public function test_excluding_promoted_items(): void
    {
        $this->coupon(['exclude_promoted_items' => true]);
        $promoted = $this->makeVariant('FH-PROMO', 50000);
        $this->promote($promoted);

        $this->quote('HELENA10', 1, null, $promoted)->assertJsonPath('data.coupon_error.message', 'Este cupón no aplica a productos en promoción');

        // Mixed cart: only the full-price line is eligible (50.000 * 10% = 5.000).
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $promoted->id, 'quantity' => 1], ['variant_id' => $this->variant->id, 'quantity' => 1]], 'coupon_code' => 'HELENA10'])
            ->assertJsonPath('data.discount_cop', 5000)->assertJsonPath('data.total_cop', 40000 + 50000 - 5000);
    }

    public function test_coupon_combines_with_a_promotion_on_the_final_price(): void
    {
        $this->coupon();
        $promoted = $this->makeVariant('FH-PROMO', 50000);
        $this->promote($promoted); // final price 40.000

        $this->quote('HELENA10', 1, null, $promoted)->assertJsonPath('data.subtotal_cop', 40000)->assertJsonPath('data.discount_cop', 4000)->assertJsonPath('data.total_cop', 36000);
    }

    // ---- Store ----

    public function test_store_saves_the_discount_the_coupon_and_the_redemption(): void
    {
        $coupon = $this->coupon();

        $this->order(['coupon_code' => 'helena10', 'expected_total_cop' => 90000])->assertCreated()
            ->assertJsonPath('order.total_cop', 90000)->assertJsonPath('order.discount_cop', 10000)->assertJsonPath('order.coupon_code', 'HELENA10');

        $order = Order::firstOrFail();
        $this->assertSame(10000, $order->discount_cop);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame('HELENA10', $order->coupon_code);
        $this->assertDatabaseHas('coupon_redemptions', ['coupon_id' => $coupon->id, 'order_id' => $order->id, 'email' => 'maria@example.com', 'discount_cop' => 10000, 'released_at' => null]);
    }

    public function test_store_refuses_a_coupon_that_stopped_being_valid_and_creates_nothing(): void
    {
        $this->coupon(['ends_at' => now()->addHour()]);
        $this->travel(2)->hours();

        $this->order(['coupon_code' => 'HELENA10'])->assertStatus(422)->assertJsonPath('errors.coupon_code.0', 'Cupón no válido o vencido');
        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->variant->fresh()->stock_quantity);
    }

    public function test_store_refuses_the_last_use_when_it_was_already_spent(): void
    {
        $this->coupon(['max_uses' => 1]);
        $this->order(['coupon_code' => 'HELENA10', 'idempotency_key' => 'first-key-0123456789abcdef'])->assertCreated();

        $this->order(['coupon_code' => 'HELENA10', 'idempotency_key' => 'second-key-0123456789abcdef', 'customer_email' => 'otra@example.com'])
            ->assertStatus(422)->assertJsonPath('errors.coupon_code.0', 'Este cupón ya alcanzó su límite de usos');
        $this->assertSame(1, Order::count());
    }

    public function test_the_same_idempotency_key_registers_a_single_use(): void
    {
        $coupon = $this->coupon();

        $this->order(['coupon_code' => 'HELENA10'])->assertCreated();
        $this->order(['coupon_code' => 'HELENA10'])->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, $coupon->redemptions()->count());
    }

    public function test_a_changed_discount_is_refused_by_the_expected_total(): void
    {
        $this->coupon();

        $this->order(['coupon_code' => 'HELENA10', 'expected_total_cop' => 100000])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_releasing_an_order_gives_the_use_back_once(): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        $this->order(['coupon_code' => 'HELENA10'])->assertCreated();
        $order = Order::firstOrFail();

        $this->assertTrue(app(CouponService::class)->releaseForOrder($order));
        $this->assertFalse(app(CouponService::class)->releaseForOrder($order));
        $this->assertSame('current', app(CouponService::class)->status($coupon->fresh()));
        $this->quote('HELENA10')->assertJsonPath('data.discount_cop', 10000);
    }

    public function test_coupon_validation_is_rate_limited_but_plain_quotes_are_not(): void
    {
        $this->coupon();

        for ($i = 0; $i < 10; $i++) {
            $this->quote('ADIVINA'.$i)->assertOk();
        }
        $this->quote('ADIVINA11')->assertStatus(429);

        // Quotes without a code keep the normal limit.
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])->assertOk();
    }

    public function test_quotes_do_not_use_up_the_order_limit(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])->assertOk();
        }

        $this->order()->assertCreated();
    }

    // ---- Admin ----

    private function form(array $override = []): array
    {
        return $override + [
            'code' => 'fh-7k2m', 'description' => 'Campaña de prueba', 'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount_cop' => null,
            'min_subtotal_cop' => 0, 'max_uses' => null, 'exclude_promoted_items' => false, 'is_active' => true, 'starts_at' => null, 'ends_at' => null,
        ];
    }

    public function test_only_an_administrator_manages_coupons(): void
    {
        $coupon = $this->coupon();
        $customer = User::factory()->create();

        $this->getJson('/admin/coupons/data')->assertUnauthorized();
        $this->postJson('/admin/coupons', $this->form())->assertUnauthorized();
        foreach ([['get', '/admin/coupons/data'], ['post', '/admin/coupons'], ['get', "/admin/coupons/{$coupon->id}"], ['put', "/admin/coupons/{$coupon->id}"], ['patch', "/admin/coupons/{$coupon->id}/status"], ['delete', "/admin/coupons/{$coupon->id}"]] as [$method, $url]) {
            $this->actingAs($customer)->json($method, $url, $this->form())->assertForbidden();
        }
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_creates_edits_lists_and_toggles_a_coupon(): void
    {
        $admin = $this->admin();
        $start = now('America/Bogota')->addDay()->setTime(8, 0); // tomorrow 08:00 in Colombia: always in the future, whatever day the test runs

        $created = $this->actingAs($admin)->postJson('/admin/coupons', $this->form(['starts_at' => $start->format('Y-m-d\TH:i')]))->assertCreated()
            ->assertJsonPath('data.code', 'FH-7K2M')->assertJsonPath('data.status', 'scheduled')->assertJsonPath('summary.scheduled', 1);
        $id = $created->json('data.id');
        $this->assertSame($start->copy()->addHours(5)->format('Y-m-d H:i:s'), Coupon::find($id)->starts_at->format('Y-m-d H:i:s')); // Bogotá is UTC-5
        $this->assertSame($admin->id, Coupon::find($id)->created_by);

        $this->actingAs($admin)->putJson("/admin/coupons/$id", $this->form(['discount_value' => 15, 'starts_at' => null]))->assertOk()->assertJsonPath('data.status', 'current')->assertJsonPath('data.discount_label', '-15%');
        $this->actingAs($admin)->patchJson("/admin/coupons/$id/status", ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('summary.inactive', 1);
        $this->actingAs($admin)->getJson('/admin/coupons/data?status=inactive&search=7k2')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/admin/coupons/data?status=current')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson("/admin/coupons/$id")->assertOk()->assertJsonPath('data.starts_at_local', null);
    }

    public function test_admin_form_validation_errors_come_back_per_field(): void
    {
        $admin = $this->admin();
        $this->coupon(['code' => 'REPETIDO']);

        $this->actingAs($admin)->postJson('/admin/coupons', $this->form(['code' => 'repetido']))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson('/admin/coupons', $this->form(['code' => 'a b']))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson('/admin/coupons', $this->form(['discount_value' => 95]))->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $this->actingAs($admin)->postJson('/admin/coupons', $this->form(['discount_type' => 'fixed_amount', 'max_discount_cop' => 5000]))->assertStatus(422)->assertJsonValidationErrors('max_discount_cop');
        $start = now('America/Bogota')->addDay()->setTime(8, 0);
        $this->actingAs($admin)->postJson('/admin/coupons', $this->form(['starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->copy()->subDay()->format('Y-m-d\TH:i')]))->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_a_used_coupon_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $admin = $this->admin();
        $used = $this->coupon();
        $free = $this->coupon(['code' => 'LIBRE']);
        $this->order(['coupon_code' => 'HELENA10'])->assertCreated();

        $this->actingAs($admin)->deleteJson("/admin/coupons/{$used->id}")->assertStatus(409)->assertJsonPath('message', 'Este cupón ya se usó en pedidos; puedes desactivarlo.');
        $this->assertDatabaseHas('coupons', ['id' => $used->id]);
        $this->actingAs($admin)->deleteJson("/admin/coupons/{$free->id}")->assertOk();
        $this->assertDatabaseMissing('coupons', ['id' => $free->id]);
    }

    public function test_orders_in_the_admin_show_the_coupon(): void
    {
        $admin = $this->admin();
        $this->coupon();
        $this->order(['coupon_code' => 'HELENA10'])->assertCreated();
        $order = Order::firstOrFail();

        $this->actingAs($admin)->getJson('/admin/orders/data')->assertJsonPath('data.0.coupon_code', 'HELENA10')->assertJsonPath('data.0.discount_cop', 10000);
        $this->order(['idempotency_key' => 'plain-key-0123456789abcdef'])->assertCreated();
        $this->actingAs($admin)->getJson('/admin/orders/data?coupon=with')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.coupon_code', 'HELENA10');
        $this->actingAs($admin)->getJson('/admin/orders/data?coupon=without')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.coupon_code', null);
        $this->actingAs($admin)->getJson('/admin/orders/data?coupon=otro')->assertStatus(422);
        $this->actingAs($admin)->getJson("/admin/orders/{$order->id}/detail")->assertJsonPath('order.coupon_code', 'HELENA10')->assertJsonPath('order.discount_cop', 10000);
    }
}
