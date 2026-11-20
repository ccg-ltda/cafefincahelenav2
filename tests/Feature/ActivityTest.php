<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Models\WhatsappRequest;
use App\Services\ActivityLogger;
use App\Support\ActivityDiff;
use App\Support\ActivityRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function admin(string $name = 'Ana Admin'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function product(int $price = 30000, int $stock = 10): array
    {
        $n = ++self::$n;
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => "Café $n", 'slug' => "cafe-$n", 'is_published' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => sprintf('FH-%03d', $n), 'name' => "Bolsa $n", 'price_cop' => $price, 'is_published' => true]);
        $variant->forceFill(['stock_quantity' => $stock])->save();

        return [$product, $variant];
    }

    private function only(string $module, string $action): ActivityLog
    {
        $rows = ActivityLog::query()->where('module', $module)->where('action', $action)->get();
        $this->assertCount(1, $rows, "Expected exactly one $module/$action row, got {$rows->count()}");

        return $rows->first();
    }

    private function changes(ActivityLog $row): array
    {
        return $row->getAttribute('changes') ?? [];
    }

    private function order(?Customer $customer = null): Order
    {
        return Order::create([
            'customer_id' => $customer?->id, 'reference' => 'CFH-ABCDEF123456', 'customer_name' => 'María', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_address' => 'Calle 1', 'delivery_city' => 'Bogotá', 'subtotal_cop' => 1000, 'total_cop' => 1000, 'currency' => 'COP', 'status' => 'pending', 'payment_status' => 'pending',
            'terms_accepted_at' => now(), 'data_consent_at' => now(),
        ]);
    }

    // ---- Categories ----

    public function test_categories_log_create_update_and_status_changes(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin)->postJson('/admin/products/categories', ['name' => 'Tés', 'is_active' => true])->assertCreated()->json('category.id');
        $created = $this->only('categories', 'created');
        $this->assertSame([$admin->id, 'category', $id, 'Categoría: Tés', 'Ana Admin'], [$created->user_id, $created->subject_type, $created->subject_id, $created->subject_label, $created->actor_label]);
        $this->assertSame(['Sí'], [$this->changes($created)['is_active'][1]]);

        $this->putJson("/admin/products/categories/$id", ['name' => 'Tés e infusiones', 'is_active' => true])->assertOk();
        $this->assertSame(['name' => ['Tés', 'Tés e infusiones']], $this->changes($this->only('categories', 'updated')));

        $this->patchJson("/admin/products/categories/$id/status", ['is_active' => false])->assertOk();
        $this->only('categories', 'deactivated');
        $this->patchJson("/admin/products/categories/$id/status", ['is_active' => false])->assertOk(); // no change, no row
        $this->assertSame(3, ActivityLog::count());
    }

    // ---- Products and variants ----

    public function test_products_log_create_update_status_and_variants(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', fn ($pattern, $value) => preg_match('/'.$pattern.'/', (string) $value) ? 1 : 0, 2);
        $admin = $this->admin();
        $category = Category::create(['name' => 'Café', 'slug' => 'cafe', 'is_active' => true]);

        $id = $this->actingAs($admin)->postJson('/admin/products', ['name' => 'Café Nuevo', 'category_id' => $category->id, 'is_published' => true])->assertCreated()->json('product.id');
        $created = $this->only('products', 'created');
        $this->assertSame(['product', $id, 'Producto: Café Nuevo'], [$created->subject_type, $created->subject_id, $created->subject_label]);

        $this->putJson("/admin/products/$id", ['name' => 'Café Nuevo', 'category_id' => $category->id, 'short_description' => 'Suave', 'is_published' => true])->assertOk();
        $this->assertSame(['short_description' => [null, 'Suave']], $this->changes($this->only('products', 'updated')));

        $this->patchJson("/admin/products/$id/status", ['is_published' => false])->assertOk();
        $this->only('products', 'deactivated');

        $variantId = $this->postJson("/admin/products/$id/variants", ['name' => 'Bolsa 250 g', 'price_cop' => 15000, 'is_published' => true, 'stock_quantity' => 7])->assertCreated()->json('product.variants.0.id');
        $row = ActivityLog::query()->where('subject_type', 'product_variant')->where('action', 'created')->get();
        $this->assertCount(1, $row);
        $this->assertSame(15000, $this->changes($row->first())['price_cop'][1]);
        $this->assertSame(7, $this->changes($row->first())['stock_quantity'][1]);

        $this->putJson("/admin/products/$id/variants/$variantId", ['name' => 'Bolsa 250 g', 'price_cop' => 18000, 'is_published' => true, 'stock_quantity' => 7, 'expected_stock' => 7])->assertOk();
        $update = ActivityLog::query()->where('subject_type', 'product_variant')->where('action', 'updated')->get();
        $this->assertCount(1, $update);
        $this->assertSame(['price_cop' => [15000, 18000]], $this->changes($update->first()));

        $this->patchJson("/admin/products/$id/variants/$variantId/status", ['is_published' => false])->assertOk();
        $this->assertSame(1, ActivityLog::query()->where('subject_type', 'product_variant')->where('action', 'deactivated')->count());
    }

    // ---- Inventory ----

    public function test_a_stock_adjustment_logs_one_row_with_before_and_after_and_a_sale_logs_none(): void
    {
        [$product, $variant] = $this->product(stock: 10);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/admin/products/{$product->id}/variants/{$variant->id}/stock", ['stock_quantity' => 25, 'expected_stock' => 10])->assertOk();
        $row = $this->only('inventory', 'stock_adjusted');
        $this->assertSame(['stock_quantity' => [10, 25]], $this->changes($row));
        $this->assertSame('Ajuste desde listado', $row->context['reason']);

        // A stock conflict (409) writes nothing; an unchanged value neither.
        $this->postJson("/admin/products/{$product->id}/variants/{$variant->id}/stock", ['stock_quantity' => 30, 'expected_stock' => 10])->assertStatus(409);
        $this->postJson("/admin/products/{$product->id}/variants/{$variant->id}/stock", ['stock_quantity' => 25, 'expected_stock' => 25])->assertOk();
        $this->assertSame(1, ActivityLog::count());

        // Checkout sales are the customer's doing: not in the trail.
        auth()->logout();
        $this->postJson('/api/orders', [
            'idempotency_key' => 'activity-sale-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame(1, ActivityLog::count());
    }

    // ---- Promotions ----

    public function test_promotions_log_create_update_status_and_delete(): void
    {
        [$product, $variant] = $this->product();
        $admin = $this->admin();
        $body = ['product_id' => $product->id, 'variant_ids' => [$variant->id], 'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true, 'starts_at' => null, 'ends_at' => null];

        $id = $this->actingAs($admin)->postJson('/admin/promotions', $body)->assertCreated()->json('data.id');
        $created = $this->only('promotions', 'created');
        $this->assertSame(20, $this->changes($created)['discount_value'][1]);
        $this->assertStringStartsWith("Promoción #$id:", $created->subject_label);

        $this->putJson("/admin/promotions/$id", ['discount_value' => 30] + $body)->assertOk();
        $this->assertSame(['discount_value' => [20, 30]], $this->changes($this->only('promotions', 'updated')));
        $this->putJson("/admin/promotions/$id", ['discount_value' => 30] + $body)->assertOk(); // nothing changed: no new row
        $this->assertSame(1, ActivityLog::query()->where('action', 'updated')->count());

        $this->patchJson("/admin/promotions/$id/status", ['is_active' => false])->assertOk();
        $this->only('promotions', 'deactivated');
        $this->deleteJson("/admin/promotions/$id")->assertOk();
        $deleted = $this->only('promotions', 'deleted');
        $this->assertSame($id, $deleted->subject_id);
        $this->assertNull(Promotion::find($id));
    }

    // ---- Coupons ----

    public function test_coupons_log_create_update_status_and_delete(): void
    {
        $admin = $this->admin();
        $body = ['code' => 'helena10', 'discount_type' => 'percentage', 'discount_value' => 10, 'min_subtotal_cop' => 0, 'exclude_promoted_items' => false, 'is_active' => true];

        $id = $this->actingAs($admin)->postJson('/admin/coupons', $body)->assertCreated()->json('data.id');
        $this->assertSame('Cupón: HELENA10', $this->only('coupons', 'created')->subject_label);

        $this->putJson("/admin/coupons/$id", ['discount_value' => 15] + $body)->assertOk();
        $this->assertSame(['discount_value' => [10, 15]], $this->changes($this->only('coupons', 'updated')));

        $this->patchJson("/admin/coupons/$id/status", ['is_active' => false])->assertOk();
        $this->only('coupons', 'deactivated');
        $this->patchJson("/admin/coupons/$id/status", ['is_active' => true])->assertOk();
        $this->only('coupons', 'activated');

        $this->deleteJson("/admin/coupons/$id")->assertOk();
        $this->only('coupons', 'deleted');
        $this->assertNull(Coupon::find($id));
    }

    // ---- Customers ----

    public function test_marketing_opt_out_and_csv_export_are_logged_without_personal_data(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['email' => 'ana.perez@dominio.com', 'name' => 'Ana Pérez', 'phone' => '3001234567', 'city' => 'Bogotá', 'marketing_opt_in_at' => now()->subDay()]);
        $order = $this->order($customer);
        $this->assertNotNull($order->id);

        $this->actingAs($admin)->patchJson("/admin/customers/{$customer->id}/marketing-opt-out")->assertOk();
        $row = $this->only('customers', 'marketing_opted_out');
        $this->assertSame('Cliente: a***@dominio.com', $row->subject_label);
        $this->assertStringNotContainsString('ana.perez', json_encode($row->getAttributes()));

        $this->get('/admin/customers/export?search=ana.perez@dominio.com')->assertOk();
        $export = $this->only('customers', 'exported');
        $this->assertSame(1, $export->context['rows']);
        $this->assertSame('a***@dominio.com', $export->context['search']);
    }

    // ---- WhatsApp ----

    public function test_whatsapp_actions_log_one_row_each_without_message_or_phone(): void
    {
        $admin = $this->admin();
        $other = $this->admin('Beto Admin');

        $id = $this->actingAs($admin)->postJson('/admin/whatsapp/requests', ['name' => 'Cafetería El Roble', 'phone' => '300 123 4567', 'topic' => 'wholesale', 'message' => 'Quiero 20 kg de café para mi cafetería.'])->assertCreated()->json('data.id');
        $created = $this->only('whatsapp', 'created');
        $this->assertSame(['topic' => [null, 'wholesale']], $this->changes($created));
        $this->assertStringNotContainsString('3001234567', json_encode($created->getAttributes()));
        $this->assertStringNotContainsString('20 kg', json_encode($created->getAttributes()));

        $this->patchJson("/admin/whatsapp/requests/$id/take")->assertOk();
        $this->assertSame(['in_progress'], [$this->changes($this->only('whatsapp', 'taken'))['status'][1]]);
        $this->patchJson("/admin/whatsapp/requests/$id/assign", ['user_id' => $other->id])->assertOk();
        $this->only('whatsapp', 'assigned');
        $this->patchJson("/admin/whatsapp/requests/$id/notes", ['internal_notes' => 'Llamar mañana al 3001234567'])->assertOk();
        $notes = $this->only('whatsapp', 'notes_updated');
        $this->assertSame([], $this->changes($notes));
        $this->assertStringNotContainsString('Llamar', json_encode($notes->getAttributes()));
        $this->patchJson("/admin/whatsapp/requests/$id/resolve", ['note' => 'Listo'])->assertOk();
        $this->only('whatsapp', 'resolved');
        $this->patchJson("/admin/whatsapp/requests/$id/reopen")->assertOk();
        $this->only('whatsapp', 'reopened');

        $reply = \App\Models\WhatsappQuickReply::query()->first() ?? \App\Models\WhatsappQuickReply::create(['topic' => 'wholesale', 'title' => 'Hola', 'body' => 'Texto inicial', 'sort_order' => 1]);
        $this->putJson("/admin/whatsapp/quick-replies/{$reply->id}", ['title' => 'Título nuevo', 'body' => $reply->body])->assertOk();
        $this->assertArrayHasKey('title', $this->changes($this->only('whatsapp', 'quick_reply_updated')));
        $this->assertSame(1, WhatsappRequest::count());
    }

    // ---- Orders (defined now, connected by the order management module later) ----

    public function test_orders_status_changed_is_a_valid_logged_action(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        $changes = ActivityDiff::between(['status' => 'pending'], ['status' => 'cancelled']);

        app(ActivityLogger::class)->log('orders', 'status_changed', $order, $changes, ['reason' => 'Cliente lo pidió'], actor: $admin);

        $row = $this->only('orders', 'status_changed');
        $this->assertSame(['Pedido: CFH-ABCDEF123456', 'order'], [$row->subject_label, $row->subject_type]);
        $this->assertSame(['status' => ['pending', 'cancelled']], $this->changes($row));
    }

    // ---- Rollback ----

    public function test_a_rolled_back_action_leaves_no_record(): void
    {
        $admin = $this->admin();
        try {
            DB::transaction(function () use ($admin) {
                app(ActivityLogger::class)->log('categories', 'created', null, [], [], 'Categoría: X', $admin);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, ActivityLog::count());

        // A real action that fails validation after the audit point: stock conflict leaves nothing behind.
        [$product, $variant] = $this->product(stock: 10);
        $this->actingAs($admin)->postJson("/admin/products/{$product->id}/variants/{$variant->id}/stock", ['stock_quantity' => 3, 'expected_stock' => 99])->assertStatus(409);
        $this->assertSame(0, ActivityLog::count());
    }

    // ---- Redaction and diff ----

    public function test_sensitive_fields_are_never_stored(): void
    {
        $admin = $this->admin();
        app(ActivityLogger::class)->log('products', 'updated', null, [
            'password' => ['abc123', 'xyz789'], 'remember_token' => ['a', 'b'], 'Wompi_Private_Key' => ['k1', 'k2'], 'name' => ['A', 'B'],
        ], ['api_key' => 'sk_live_123', 'nested' => ['signature' => 'sig', 'ok' => 'visible'], 'reason' => 'x'], 'Prueba', $admin);

        $row = ActivityLog::first();
        $stored = json_encode($row->getAttributes());
        foreach (['abc123', 'xyz789', 'sk_live_123', 'k1', 'k2', '"sig"'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertSame([ActivityRedactor::MASK, ActivityRedactor::MASK], $this->changes($row)['password']);
        $this->assertSame(ActivityRedactor::MASK, $row->context['api_key']);
        $this->assertSame(['signature' => ActivityRedactor::MASK, 'ok' => 'visible'], $row->context['nested']);
        $this->assertSame(['A', 'B'], $this->changes($row)['name']);
    }

    public function test_the_diff_keeps_only_what_changed_in_readable_values(): void
    {
        $diff = ActivityDiff::between(
            ['price_cop' => 1000, 'is_active' => true, 'name' => 'A', 'same' => 'x', 'starts_at' => null],
            ['price_cop' => '1500', 'is_active' => false, 'name' => 'A', 'same' => 'x', 'starts_at' => now()->setTimezone('America/Bogota')->setDate(2026, 11, 19)->setTime(8, 0)],
        );

        $this->assertSame([1000, 1500], $diff['price_cop']);
        $this->assertSame(['Sí', 'No'], $diff['is_active']);
        $this->assertSame([null, '2026-11-19T13:00:00+00:00'], $diff['starts_at']);
        $this->assertArrayNotHasKey('name', $diff);
        $this->assertArrayNotHasKey('same', $diff);
    }

    // ---- Immutability ----

    public function test_a_record_cannot_be_updated_or_deleted(): void
    {
        app(ActivityLogger::class)->log('products', 'updated', null, [], [], 'Prueba');
        $row = ActivityLog::first();

        $this->expectException(LogicException::class);
        $row->update(['module' => 'coupons']);
    }

    public function test_a_record_cannot_be_deleted(): void
    {
        app(ActivityLogger::class)->log('products', 'updated', null, [], [], 'Prueba');

        $this->expectException(LogicException::class);
        ActivityLog::first()->delete();
    }

    public function test_there_is_no_route_that_edits_or_deletes_records(): void
    {
        $admin = $this->admin();
        app(ActivityLogger::class)->log('products', 'updated', null, [], [], 'Prueba');
        $id = ActivityLog::first()->id;

        foreach (['put', 'patch', 'delete', 'post'] as $method) {
            $this->assertContains($this->actingAs($admin)->json($method, "/admin/activity/$id")->status(), [404, 405]);
            $this->assertContains($this->actingAs($admin)->json($method, "/admin/activity/$id/detail")->status(), [404, 405]);
        }
        $this->assertSame(1, ActivityLog::count());
    }

    // ---- Access ----

    public function test_login_failure_and_logout_are_logged_without_the_password(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['email' => 'ana@dominio.com', 'password' => 'secret-pass-123'])->save();

        $this->postJson('/admin/login', ['email' => 'ana@dominio.com', 'password' => 'wrong-pass-999'])->assertStatus(422);
        $failed = $this->only('auth', 'login_failed');
        $this->assertSame('a***@dominio.com', $failed->context['login']);
        $this->assertSame('a***@dominio.com', $failed->actor_label);
        $this->assertNull($failed->user_id);
        $this->assertStringNotContainsString('wrong-pass', json_encode($failed->getAttributes()));
        $this->assertStringNotContainsString('secret-pass', json_encode($failed->getAttributes()));

        $this->postJson('/admin/login', ['email' => 'ana@dominio.com', 'password' => 'secret-pass-123'])->assertOk();
        $login = $this->only('auth', 'login');
        $this->assertSame([$admin->id, 'Ana Admin'], [$login->user_id, $login->actor_label]);

        $this->postJson('/admin/logout')->assertOk();
        $this->assertSame($admin->id, $this->only('auth', 'logout')->user_id);
    }

    public function test_a_login_of_a_deactivated_user_is_logged_as_failed_with_a_note(): void
    {
        $user = User::factory()->create(['email' => 'cliente@dominio.com', 'password' => 'clave-123456', 'is_active' => false]);

        $this->postJson('/admin/login', ['email' => 'cliente@dominio.com', 'password' => 'clave-123456'])->assertStatus(422);

        $row = $this->only('auth', 'login_failed');
        $this->assertSame('usuario desactivado', $row->context['note']);
        $this->assertNull($row->user_id);
        $this->assertNotNull($user->id);
    }

    public function test_the_attempted_email_is_masked_cut_and_never_a_free_text(): void
    {
        $this->postJson('/admin/login', ['email' => str_repeat('a', 60).'@'.str_repeat('b', 60).'.com', 'password' => 'x'])->assertStatus(422);
        $this->postJson('/admin/login', ['email' => 'nadie@dominio.com', 'password' => 'x'])->assertStatus(422);

        $rows = ActivityLog::query()->where('action', 'login_failed')->orderBy('id')->get();
        $this->assertSame('[valor no válido]', $rows[0]->context['login']);
        $this->assertSame('n***@dominio.com', $rows[1]->context['login']);
    }

    // ---- Listing, filters, drawer ----

    private function seedRows(): array
    {
        $ana = $this->admin('Ana Admin');
        $beto = $this->admin('Beto Admin');
        $logger = app(ActivityLogger::class);
        $at = fn (string $when) => fn () => null;

        $logger->log('coupons', 'created', null, ['code' => [null, 'A']], [], 'Cupón: A', $ana);
        $logger->log('products', 'updated', null, ['name' => ['x', 'y']], [], 'Producto: X', $beto);
        $logger->log('auth', 'login', null, [], [], 'Inicio de sesión', $ana);
        $logger->log('auth', 'login_failed', null, [], ['email' => 'a***@x.com'], 'Inicio de sesión', null, 'a***@x.com');
        // Spread the rows in time (Bogotá): 2026-11-10 .. 2026-11-13.
        foreach (ActivityLog::query()->orderBy('id')->get() as $i => $row) {
            DB::table('activity_logs')->where('id', $row->id)->update(['occurred_at' => '2026-11-1'.$i.' 15:00:00']);
        }

        return [$ana, $beto];
    }

    public function test_the_listing_hides_accesses_by_default_and_filters_by_admin_module_action_and_dates(): void
    {
        [$ana, $beto] = $this->seedRows();
        $this->actingAs($ana);

        $this->assertCount(2, $this->getJson('/admin/activity/data')->assertOk()->json('data'));
        $this->assertCount(4, $this->getJson('/admin/activity/data?include_auth=1')->json('data'));
        $this->assertSame(['Producto: X'], collect($this->getJson("/admin/activity/data?admin={$beto->id}")->json('data'))->pluck('subject_label')->all());
        $this->assertSame(['Cupón: A'], collect($this->getJson('/admin/activity/data?module=coupons&action=created')->json('data'))->pluck('subject_label')->all());
        $this->assertCount(2, $this->getJson('/admin/activity/data?module=auth')->json('data')); // choosing "Accesos" shows them
        $this->assertCount(0, $this->getJson('/admin/activity/data?module=coupons&action=deleted')->json('data'));
        // 2026-11-11 15:00 UTC = 10:00 in Bogotá: the Bogotá day 11 holds only the second row.
        $this->assertSame(['Producto: X'], collect($this->getJson('/admin/activity/data?from=2026-11-11&to=2026-11-11')->json('data'))->pluck('subject_label')->all());
        $this->assertCount(0, $this->getJson('/admin/activity/data?from=2026-12-01')->json('data'));
        $this->getJson('/admin/activity/data?from=2026-11-12&to=2026-11-10')->assertStatus(422);
        $this->getJson('/admin/activity/data?module=hacker')->assertStatus(422);
        $this->getJson('/admin/activity/data?admin=1%27%20OR%201=1')->assertStatus(422); // filters are validated and bound, never concatenated
    }

    public function test_the_listing_is_newest_first_paged_by_50_and_labelled_in_spanish(): void
    {
        $admin = $this->admin();
        $logger = app(ActivityLogger::class);
        for ($i = 1; $i <= 55; $i++) {
            $logger->log('coupons', 'created', null, [], [], "Cupón: C$i", $admin);
        }

        $page = $this->actingAs($admin)->getJson('/admin/activity/data')->assertOk()->json();
        $this->assertCount(50, $page['data']);
        $this->assertSame([55, 2], [$page['meta']['total'], $page['meta']['last_page']]);
        $this->assertSame('Cupón: C55', $page['data'][0]['subject_label']);
        $this->assertSame(['Cupones', 'Creó'], [$page['data'][0]['module_label'], $page['data'][0]['action_label']]);
        $this->assertCount(5, $this->getJson('/admin/activity/data?page=2')->json('data'));
    }

    public function test_the_row_links_to_the_resource_only_while_it_exists(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        $logger = app(ActivityLogger::class);
        $logger->log('orders', 'status_changed', $order, ['status' => ['pending', 'paid']], [], null, $admin);
        $coupon = Coupon::create(['code' => 'GONE', 'discount_type' => 'percentage', 'discount_value' => 5, 'is_active' => true]);
        $logger->log('coupons', 'created', $coupon, [], [], null, $admin);
        $coupon->delete();

        $rows = collect($this->actingAs($admin)->getJson('/admin/activity/data')->json('data'))->keyBy('subject_type');
        $this->assertSame("/admin/orders/{$order->id}", $rows['order']['link']);
        $this->assertNull($rows['coupon']['link']);
    }

    public function test_the_detail_shows_changes_context_and_technical_data(): void
    {
        $admin = $this->admin();
        [$product] = $this->product();
        $logger = app(ActivityLogger::class);
        $logger->log('promotions', 'updated', $product, [
            'discount_value' => [20, 30], 'min_subtotal_cop' => [1000, 25000], 'is_active' => ['Sí', 'No'], 'starts_at' => [null, '2026-11-19T20:09:00+00:00'], 'category_id' => [1, $product->category_id],
        ], ['reason' => 'Ajuste de campaña'], null, $admin);
        $id = ActivityLog::first()->id;

        $detail = $this->actingAs($admin)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36')
            ->getJson("/admin/activity/$id/detail")->assertOk()->json('data');
        $byField = collect($detail['changes'])->keyBy('field');
        $this->assertSame(['20', '30'], [$byField['discount_value']['before'], $byField['discount_value']['after']]);
        $this->assertSame('$ 25.000', $byField['min_subtotal_cop']['after']);
        $this->assertSame(['—', '19/11/2026, 3:09 p. m.'], [$byField['starts_at']['before'], $byField['starts_at']['after']]);
        $this->assertSame('Café', $byField['category_id']['after']);
        $this->assertSame([['label' => 'Motivo', 'value' => 'Ajuste de campaña']], $detail['context']);
        $this->getJson('/admin/activity/999999/detail')->assertNotFound();

        // The request that wrote a row is identified; a console-written row has no network data.
        $this->assertNull($detail['technical']['ip']);
    }

    public function test_requests_get_an_ip_browser_and_request_id(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36')
            ->postJson('/admin/products/categories', ['name' => 'Tés', 'is_active' => true])->assertCreated();

        $row = ActivityLog::first();
        $this->assertNotNull($row->ip);
        $this->assertNotNull($row->request_id);
        $this->assertLessThanOrEqual(255, strlen($row->user_agent));
        $detail = $this->getJson("/admin/activity/{$row->id}/detail")->json('data');
        $this->assertSame('Chrome 120 · Windows', $detail['technical']['browser']);
    }

    // ---- CSV ----

    public function test_the_csv_follows_the_filter_is_safe_and_is_itself_logged(): void
    {
        [$ana] = $this->seedRows();
        app(ActivityLogger::class)->log('products', 'updated', null, [], [], '=HYPERLINK("x")', $ana);

        $response = $this->actingAs($ana)->get('/admin/activity/export?module=products');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Fecha;Administrador;Módulo;Acción;Recurso;Cambios;Contexto;IP', $csv);
        $this->assertStringContainsString('Producto: X', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Cupón: A', $csv);

        $export = $this->only('activity', 'exported');
        $this->assertSame(2, $export->context['rows']);
        $this->assertSame('products', $export->context['module']);
        $this->assertStringNotContainsString('Historial: exportación', $csv); // its own row is not part of the file
    }

    // ---- Retention ----

    public function test_prune_removes_only_old_access_rows(): void
    {
        $admin = $this->admin();
        $logger = app(ActivityLogger::class);
        $logger->log('auth', 'login', null, [], [], 'old auth', $admin);
        $logger->log('auth', 'login', null, [], [], 'recent auth', $admin);
        $logger->log('coupons', 'created', null, [], [], 'old admin action', $admin);
        $rows = ActivityLog::query()->orderBy('id')->get();
        DB::table('activity_logs')->whereIn('id', [$rows[0]->id, $rows[2]->id])->update(['occurred_at' => now()->subDays(400)]);
        DB::table('activity_logs')->where('id', $rows[1]->id)->update(['occurred_at' => now()->subDays(179)]);

        $this->artisan('activity:prune')->assertExitCode(0);

        $this->assertSame(['recent auth', 'old admin action'], ActivityLog::query()->orderBy('id')->pluck('subject_label')->all());
    }

    public function test_the_retention_is_configurable(): void
    {
        config(['shop.activity.auth_retention_days' => 30]);
        $admin = $this->admin();
        app(ActivityLogger::class)->log('auth', 'login', null, [], [], 'login', $admin);
        DB::table('activity_logs')->update(['occurred_at' => now()->subDays(31)]);

        $this->artisan('activity:prune')->assertExitCode(0);

        $this->assertSame(0, ActivityLog::count());
    }

    // ---- Permissions ----

    public function test_only_administrators_can_see_the_history(): void
    {
        $paths = ['/admin/activity', '/admin/activity/1', '/admin/activity/data', '/admin/activity/export', '/admin/activity/1/detail'];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->getJson('/admin/activity/data')->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        foreach ($paths as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->actingAs($this->admin());
        $this->get('/admin/activity')->assertOk()->assertSee('Historial');
        $this->get('/admin/activity/1')->assertOk();
    }
}
