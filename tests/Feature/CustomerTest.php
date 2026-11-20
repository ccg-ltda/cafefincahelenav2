<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $category = Category::create(['slug' => 'cafe', 'name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Café Premium', 'slug' => 'cafe-premium', 'is_published' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g', 'price_cop' => 50000, 'is_published' => true]);
        $this->variant->forceFill(['stock_quantity' => 100])->save();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function buy(array $override = [])
    {
        return $this->postJson('/api/orders', array_merge([
            'idempotency_key' => 'key-'.++$this->seq.'-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com',
            'customer_phone' => '3001234567', 'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34',
            'delivery_details' => 'Apto 201', 'terms_accepted' => true, 'data_consent' => true, 'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
        ], $override));
    }

    /** An order of a customer created directly, with a given age (days ago), status and total. */
    private function order(Customer $customer, int $daysAgo, int $total = 50000, string $status = 'pending', string $payment = 'pending'): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id, 'reference' => 'CFH-T'.++$this->seq, 'customer_name' => $customer->name, 'customer_email' => $customer->email, 'customer_phone' => $customer->phone,
            'delivery_address' => 'Calle 1', 'delivery_city' => $customer->city, 'subtotal_cop' => $total, 'total_cop' => $total, 'currency' => 'COP', 'status' => $status, 'payment_status' => $payment,
            'terms_accepted_at' => now(), 'data_consent_at' => now(),
        ]);
        $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $order;
    }

    private function customer(string $email, string $name = 'Cliente Prueba'): Customer
    {
        return Customer::create(['email' => $email, 'name' => $name, 'phone' => '3001234567', 'city' => 'Bogotá']);
    }

    // ---- Creating customers from orders ----

    public function test_a_new_purchase_creates_the_customer_and_links_the_order(): void
    {
        $this->buy()->assertCreated();

        $customer = Customer::firstOrFail();
        $this->assertSame('maria@example.com', $customer->email);
        $this->assertSame(['María González', '3001234567', 'Bogotá', 'Cundinamarca', 'Calle 45 # 12-34', 'Apto 201'], [$customer->name, $customer->phone, $customer->city, $customer->department, $customer->last_address, $customer->last_address_details]);
        $this->assertSame($customer->id, Order::firstOrFail()->customer_id);
    }

    public function test_a_second_purchase_with_the_same_email_updates_the_customer_without_duplicating(): void
    {
        $this->buy()->assertCreated();
        $this->buy(['customer_email' => '  MARIA@Example.COM ', 'customer_name' => 'María G. Pérez', 'customer_phone' => '3109998888', 'delivery_city' => 'Medellín', 'delivery_department' => 'Antioquia', 'delivery_address' => 'Carrera 7 # 1-1', 'delivery_details' => null])->assertCreated();

        $this->assertSame(1, Customer::count());
        $customer = Customer::firstOrFail();
        $this->assertSame(['María G. Pérez', '3109998888', 'Medellín', 'Antioquia', 'Carrera 7 # 1-1', null], [$customer->name, $customer->phone, $customer->city, $customer->department, $customer->last_address, $customer->last_address_details]);
        $this->assertSame(2, Order::where('customer_id', $customer->id)->count());
    }

    public function test_the_same_idempotency_key_does_not_duplicate_customer_or_order(): void
    {
        $this->buy(['idempotency_key' => 'same-key-0123456789abcdef'])->assertCreated();
        $this->buy(['idempotency_key' => 'same-key-0123456789abcdef'])->assertOk();

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Order::count());
    }

    // ---- Marketing consent ----

    public function test_marketing_opt_in_is_saved_only_when_ticked_and_is_never_erased(): void
    {
        $this->buy()->assertCreated();
        $this->assertNull(Customer::firstOrFail()->marketing_opt_in_at);
        $this->assertFalse(Order::firstOrFail()->marketing_opt_in);

        $this->buy(['marketing_opt_in' => true])->assertCreated();
        $customer = Customer::firstOrFail();
        $this->assertNotNull($customer->marketing_opt_in_at);
        $this->assertTrue(Order::orderByDesc('id')->first()->marketing_opt_in);

        $this->buy()->assertCreated(); // unticked later: the earlier consent stays
        $this->assertNotNull($customer->fresh()->marketing_opt_in_at);
        $this->assertTrue($customer->fresh()->acceptsMarketing());
    }

    public function test_opt_out_wins_until_a_new_purchase_ticks_the_box_again(): void
    {
        $admin = $this->admin();
        $this->buy(['marketing_opt_in' => true])->assertCreated();
        $customer = Customer::firstOrFail();

        $this->travel(5)->minutes();
        $this->actingAs($admin)->patchJson("/admin/customers/{$customer->id}/marketing-opt-out")->assertOk()->assertJsonPath('data.accepts_marketing', false);
        $this->assertFalse($customer->fresh()->acceptsMarketing());

        $this->travel(5)->minutes();
        $this->buy()->assertCreated(); // not ticked: still opted out
        $this->assertFalse($customer->fresh()->acceptsMarketing());

        $this->travel(5)->minutes();
        $this->buy(['marketing_opt_in' => true])->assertCreated(); // ticked again: active
        $this->assertTrue($customer->fresh()->acceptsMarketing());
    }

    // ---- Valid orders and figures ----

    public function test_figures_with_the_payment_flag_off_count_every_non_cancelled_order(): void
    {
        config(['shop.require_payment_before_fulfillment' => false]);
        $customer = $this->customer('a@example.com');
        $this->order($customer, 10, 30000);
        $this->order($customer, 5, 20000, 'pending', 'paid');
        $this->order($customer, 2, 99000, 'cancelled');

        $this->actingAs($this->admin())->getJson('/admin/customers/data')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.orders_count', 2)->assertJsonPath('data.0.spent_cop', 50000);
    }

    public function test_figures_with_the_payment_flag_on_count_only_paid_orders(): void
    {
        config(['shop.require_payment_before_fulfillment' => true]);
        $customer = $this->customer('a@example.com');
        $this->order($customer, 10, 30000);
        $this->order($customer, 5, 20000, 'pending', 'paid');
        $unpaid = $this->customer('b@example.com');
        $this->order($unpaid, 3, 10000);

        $this->actingAs($this->admin())->getJson('/admin/customers/data')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.orders_count', 1)->assertJsonPath('data.0.spent_cop', 20000);
    }

    public function test_a_customer_with_only_cancelled_orders_is_not_listed(): void
    {
        $customer = $this->customer('a@example.com');
        $this->order($customer, 3, 10000, 'cancelled');

        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/admin/customers/data')->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson("/admin/customers/{$customer->id}/detail")->assertNotFound();
    }

    // ---- Filters, counters and sorting ----

    private function seedProfiles(): array
    {
        $recurring = $this->customer('recurrente@example.com', 'Rita Recurrente');
        $this->order($recurring, 20, 40000);
        $this->order($recurring, 3, 60000);
        $lost = $this->customer('perdido@example.com', 'Pedro Perdido');
        $this->order($lost, 90, 90000);
        $fresh = $this->customer('nuevo@example.com', 'Nora Nueva');
        $this->order($fresh, 6, 25000);
        $old = $this->customer('antiguo@example.com', 'Aníbal Antiguo');
        $this->order($old, 200, 10000);
        $this->order($old, 100, 10000); // two orders, last one 100 days ago

        return compact('recurring', 'lost', 'fresh', 'old');
    }

    public function test_filters_and_global_counters(): void
    {
        $this->seedProfiles();
        $admin = $this->admin();
        $emails = fn (string $query) => collect($this->actingAs($admin)->getJson('/admin/customers/data'.$query)->assertOk()->json('data'))->pluck('email')->sort()->values()->all();

        $this->assertSame(['antiguo@example.com', 'nuevo@example.com', 'perdido@example.com', 'recurrente@example.com'], $emails(''));
        $this->assertSame(['antiguo@example.com', 'recurrente@example.com'], $emails('?filter=recurring'));
        $this->assertSame(['antiguo@example.com', 'perdido@example.com'], $emails('?filter=recover'));
        $this->assertSame(['nuevo@example.com', 'recurrente@example.com'], $emails('?filter=new'));

        // Counters ignore the active pill and the search.
        $counters = $this->actingAs($admin)->getJson('/admin/customers/data?filter=new&search=nora')->json('counters');
        $this->assertSame(['all' => 4, 'recurring' => 2, 'recover' => 2, 'new' => 2, 'promos' => 0], $counters);
    }

    public function test_the_promos_filter_respects_the_opt_out(): void
    {
        $profiles = $this->seedProfiles();
        $profiles['recurring']->update(['marketing_opt_in_at' => now()->subDays(2)]);
        $profiles['fresh']->update(['marketing_opt_in_at' => now()->subDays(3), 'marketing_opt_out_at' => now()->subDay()]);
        $profiles['lost']->update(['marketing_opt_in_at' => now()->subDays(3), 'marketing_opt_out_at' => now()->subDays(5)]); // opted in again after the opt-out

        $rows = $this->actingAs($this->admin())->getJson('/admin/customers/data?filter=promos')->json('data');
        $this->assertSame(['perdido@example.com', 'recurrente@example.com'], collect($rows)->pluck('email')->sort()->values()->all());
    }

    public function test_search_and_sorting(): void
    {
        $this->seedProfiles();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/customers/data?search=NORA')->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/admin/customers/data?search=3001234567')->assertJsonPath('meta.total', 4);
        $this->assertSame('recurrente@example.com', $this->actingAs($admin)->getJson('/admin/customers/data?sort=spent&dir=desc')->json('data.0.email')); // 100.000 beats 90.000
        $this->assertSame('antiguo@example.com', $this->actingAs($admin)->getJson('/admin/customers/data?sort=last&dir=asc')->json('data.0.email'));
        $this->actingAs($admin)->getJson('/admin/customers/data?sort=hack')->assertStatus(422);
    }

    // ---- Detail ----

    public function test_detail_has_metrics_top_products_and_all_orders_with_cancelled_marked(): void
    {
        $customer = $this->customer('a@example.com');
        $valid = $this->order($customer, 10, 30000);
        $this->order($customer, 4, 70000, 'cancelled');
        OrderItem::create(['order_id' => $valid->id, 'product_id' => $this->variant->product_id, 'product_variant_id' => $this->variant->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g', 'quantity' => 3, 'unit_price_cop' => 10000, 'subtotal_cop' => 30000]);

        $data = $this->actingAs($this->admin())->getJson("/admin/customers/{$customer->id}/detail")->assertOk()->json('data');

        $this->assertSame(['orders' => 1, 'spent_cop' => 30000, 'average_ticket_cop' => 30000], $data['metrics']);
        $this->assertSame([['name' => 'Café Premium 250 g', 'units' => 3]], $data['top_products']);
        $this->assertCount(2, $data['orders']);
        $this->assertSame([false, true], array_column($data['orders'], 'counts')); // newest first: the cancelled one does not count
        $this->assertNotNull($data['consents']['data_consent_at']);
    }

    // ---- Backfill ----

    public function test_backfill_is_idempotent_and_keeps_the_newest_profile(): void
    {
        foreach ([['Maria Vieja', '3001111111', 5], ['María Nueva', '3002222222', 1]] as [$name, $phone, $days]) {
            $order = Order::create([
                'reference' => 'CFH-B'.++$this->seq, 'customer_name' => $name, 'customer_email' => ' Maria@Example.com', 'customer_phone' => $phone, 'delivery_address' => 'Calle 1',
                'delivery_city' => 'Cúcuta', 'subtotal_cop' => 1000, 'total_cop' => 1000, 'currency' => 'COP', 'terms_accepted_at' => now(), 'data_consent_at' => now(),
            ]);
            $order->forceFill(['created_at' => now()->subDays($days)])->save();
        }
        $service = app(CustomerService::class);

        $this->assertSame(['orders' => 2, 'linked' => 0, 'customers_created' => 1, 'customers_total' => 1], $service->backfill(true));
        $this->assertSame(0, Customer::count());

        $this->assertSame(['orders' => 2, 'linked' => 2, 'customers_created' => 1, 'customers_total' => 1], $service->backfill());
        $this->assertSame(['orders' => 0, 'linked' => 0, 'customers_created' => 0, 'customers_total' => 1], $service->backfill());
        $customer = Customer::firstOrFail();
        $this->assertSame(['maria@example.com', 'María Nueva', '3002222222'], [$customer->email, $customer->name, $customer->phone]);
        $this->assertSame(2, Order::where('customer_id', $customer->id)->count());
    }

    // ---- CSV ----

    public function test_the_csv_respects_the_filter_and_has_bom_semicolons_and_formula_protection(): void
    {
        $profiles = $this->seedProfiles();
        $profiles['recurring']->update(['name' => '=HYPERLINK("x")', 'marketing_opt_in_at' => now()]);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/admin/customers/export?filter=recurring')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertStringStartsWith('Nombre;Email;Celular;Ciudad;Departamento;Pedidos;Gastado;', $lines[0]);
        $this->assertCount(3, $lines); // header + the two recurring customers
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(';2;100000;', $csv);
        $this->assertStringContainsString(';sí', $csv);
        $this->assertStringNotContainsString('perdido@example.com', $csv);
    }

    // ---- Access ----

    public function test_only_an_administrator_reaches_the_customer_endpoints(): void
    {
        $customer = $this->customer('a@example.com');
        $this->order($customer, 1);
        $user = User::factory()->create();

        $endpoints = [['get', '/admin/customers/data'], ['get', '/admin/customers/export'], ['get', "/admin/customers/{$customer->id}/detail"], ['patch', "/admin/customers/{$customer->id}/marketing-opt-out"]];
        foreach ($endpoints as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        foreach ($endpoints as [$method, $url]) {
            $this->actingAs($user)->json($method, $url)->assertForbidden();
        }
        $this->assertNull($customer->fresh()->marketing_opt_out_at);
    }
}
