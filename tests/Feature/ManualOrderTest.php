<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * "Pedido manual": the administrator registers an order that came by WhatsApp or phone. It goes through the same services as the
 * storefront and is paid ONLY through Wompi (simulated here with Http::fake: nothing touches the network, the keys are fake).
 */
class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'env' => 'sandbox', 'public_key' => 'pub_test_FAKEPUBLICKEY0000000000000000', 'private_key' => 'prv_test_FAKEPRIVATEKEY000000000000000',
        'integrity_secret' => 'test_integrity_FAKEINTEGRITY000000000000', 'events_secret' => 'test_events_FAKEEVENTS000000000000000',
    ];

    private static int $n = 0;

    private ProductVariant $variant;

    private ProductVariant $soldOut;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.wompi' => self::KEYS + ['checkout_window_minutes' => 30, 'pending_expiry_minutes' => 60, 'manual_pending_expiry_hours' => 24],
            'app.url' => 'https://tienda.example.com', 'shop.admin_notification_emails' => ['dueno@tienda.example.com'],
        ]);
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Café Premium', 'slug' => 'cafe-premium', 'is_published' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g Molido', 'weight_grams' => 250, 'grind_type' => 'Molido', 'price_cop' => 50000, 'is_published' => true]);
        $this->variant->forceFill(['stock_quantity' => 10])->save();
        $this->soldOut = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FH-002', 'name' => 'Café Premium 500 g Molido', 'price_cop' => 90000, 'is_published' => true]);
    }

    // ---- helpers ----

    private function person(string $role = 'admin'): User
    {
        $n = ++self::$n;

        return User::factory()->create(['role' => $role, 'name' => "Persona $role $n", 'username' => "$role.manual$n", 'email' => "$role.manual$n@example.com"]);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'idempotency_key' => 'manual-key-'.str_pad((string) ++self::$n, 12, '0', STR_PAD_LEFT).'abcdef', 'customer_name' => 'Laura Pérez', 'customer_phone' => '300 123 4567',
            'delivery_department' => 'Norte de Santander', 'delivery_city' => 'Cúcuta', 'delivery_address' => 'Avenida 5 # 12-34', 'delivery_details' => 'Apto 301, torre B',
            'order_notes' => 'Regalo: sin factura a la vista', 'data_consent' => true, 'items' => [['variant_id' => $this->variant->id, 'quantity' => 2]],
        ];
    }

    private function create(array $override = [], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->person())->postJson('/admin/orders/manual', $this->payload($override));
    }

    private function stock(): int
    {
        return (int) $this->variant->fresh()->stock_quantity;
    }

    private function order(string $reference): Order
    {
        return Order::where('reference', $reference)->firstOrFail();
    }

    /** @return array<string, string> the query string of a Wompi checkout link */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function tx(Payment $payment, string $status = 'APPROVED'): array
    {
        return ['id' => '01-manual-'.$payment->id, 'status' => $status, 'reference' => $payment->reference, 'amount_in_cents' => $payment->amount_cop * 100, 'currency' => 'COP', 'payment_method_type' => 'CARD', 'status_message' => null];
    }

    private function approve(Payment $payment): void
    {
        $transaction = $this->tx($payment);
        $timestamp = 1530291411;
        $checksum = strtoupper(hash('sha256', $transaction['id'].$transaction['status'].$transaction['amount_in_cents'].$timestamp.self::KEYS['events_secret']));
        $this->postJson('/api/payments/wompi/events', ['event' => 'transaction.updated', 'data' => ['transaction' => $transaction], 'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => $checksum], 'timestamp' => $timestamp, 'sent_at' => '2026-11-21T10:00:00.000Z'])
            ->assertOk();
    }

    // ---- permissions ----

    public function test_only_the_administrator_may_register_manual_orders(): void
    {
        $this->assertTrue(Permissions::has('admin', 'orders.create_manual'));
        $this->assertFalse(Permissions::has('dispatch', 'orders.create_manual'));
        $this->assertFalse(Permissions::has('delivery', 'orders.create_manual'));

        $endpoints = [
            ['get', '/admin/orders/manual/products'], ['get', '/admin/orders/manual/customer?phone=3001234567'], ['post', '/admin/orders/manual/quote'],
            ['post', '/admin/orders/manual'], ['get', '/admin/orders/1/checkout-link'], ['post', '/admin/orders/1/checkout-link'],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->{$method.'Json'}($url, [])->assertUnauthorized();
        }
        foreach ($endpoints as [$method, $url]) {
            foreach (['dispatch', 'delivery'] as $role) {
                $this->actingAs($this->person($role))->{$method.'Json'}($url, $this->payload())->assertForbidden();
            }
        }
        $this->assertSame(0, Order::count());

        $this->actingAs($this->person('dispatch'))->get('/admin/orders/new')->assertForbidden();
        $this->actingAs($this->person('delivery'))->get('/admin/orders/new')->assertForbidden();
        $this->actingAs($this->person())->get('/admin/orders/new')->assertOk();
        $this->actingAs($this->person('dispatch'))->getJson('/admin/session')->assertJsonMissing(['orders.create_manual']);
        $this->actingAs($this->person())->getJson('/admin/session')->assertJsonFragment(['orders.create_manual']);
    }

    // ---- the order ----

    public function test_a_manual_order_is_created_pending_with_the_channel_the_admin_and_the_deadline(): void
    {
        $admin = $this->person();
        $response = $this->create(['customer_email' => 'Laura@Example.com', 'marketing_opt_in' => true], $admin)->assertCreated();

        $order = $this->order($response->json('order.reference'));
        $this->assertSame(['manual', $admin->id, 'pending', 'pending'], [$order->channel, $order->created_by, $order->status, $order->payment_status]);
        $this->assertSame(100000, $order->total_cop);
        $this->assertSame('3001234567', $order->customer_phone);
        $this->assertSame('laura@example.com', $order->customer_email);
        $this->assertNull($order->terms_accepted_at, 'the customer never accepted the online terms');
        $this->assertNotNull($order->data_consent_at);
        $this->assertTrue($order->marketing_opt_in);
        $this->assertSame('Regalo: sin factura a la vista', $order->order_notes);
        $this->assertNotNull($order->payment_due_at);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $order->payment_due_at->timestamp, 5);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_the_validations(): void
    {
        $admin = $this->person();
        $this->actingAs($admin);
        $bad = [
            'phone' => ['customer_phone' => '1234567'], 'phone not mobile' => ['customer_phone' => '6011234567'], 'name' => ['customer_name' => 'Ab'],
            'consent missing' => ['data_consent' => null], 'consent false' => ['data_consent' => false], 'department' => ['delivery_department' => 'Narnia'],
            'email' => ['customer_email' => 'no-es-correo'], 'no items' => ['items' => []], 'zero quantity' => ['items' => [['variant_id' => $this->variant->id, 'quantity' => 0]]],
            'more than the stock' => ['items' => [['variant_id' => $this->variant->id, 'quantity' => 11]]], 'sold out' => ['items' => [['variant_id' => $this->soldOut->id, 'quantity' => 1]]],
            'shipping negative' => ['shipping_cop' => -1], 'shipping decimal' => ['shipping_cop' => 1500.5], 'shipping text' => ['shipping_cop' => 'gratis'],
            'address' => ['delivery_address' => ''],
        ];
        foreach ($bad as $name => $override) {
            $this->postJson('/admin/orders/manual', $this->payload($override))->assertStatus(422);
        }
        $unpublished = ProductVariant::create(['product_id' => $this->variant->product_id, 'sku' => 'FH-009', 'name' => 'Oculto', 'price_cop' => 1000, 'is_published' => false]);
        $unpublished->forceFill(['stock_quantity' => 5])->save();
        $this->postJson('/admin/orders/manual', $this->payload(['items' => [['variant_id' => $unpublished->id, 'quantity' => 1]]]))->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->stock(), 'nothing was deducted by a refused order');
        $this->assertSame(0, Payment::count());
    }

    public function test_the_email_is_optional_but_the_consent_is_not(): void
    {
        $this->create()->assertCreated();
        $this->assertNull(Order::first()->customer_email);
        $this->create(['customer_email' => ''])->assertCreated();
        $this->create(['customer_email' => null, 'data_consent' => false])->assertStatus(422)->assertJsonValidationErrors('data_consent');
    }

    public function test_prices_come_from_the_database_whatever_the_browser_sends(): void
    {
        $response = $this->create([
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price_cop' => 1, 'price_cop' => 1, 'subtotal_cop' => 2]],
            'total_cop' => 1, 'subtotal_cop' => 1, 'discount_cop' => 99999, 'status' => 'confirmed', 'payment_status' => 'paid', 'channel' => 'web', 'created_by' => 999,
        ])->assertCreated();

        $order = $this->order($response->json('order.reference'));
        $this->assertSame([100000, 100000, 0, 'pending', 'pending', 'manual'], [$order->subtotal_cop, $order->total_cop, $order->discount_cop, $order->status, $order->payment_status, $order->channel]);
        $this->assertNotSame(999, (int) $order->created_by);
        $this->assertSame(50000, (int) $order->items()->first()->unit_price_cop);
        $this->create(['expected_total_cop' => 5])->assertStatus(422); // the reviewed total no longer matches
        $this->create(['expected_total_cop' => 100000])->assertCreated();
    }

    public function test_a_promotion_a_coupon_and_a_manual_shipping_are_applied_like_in_the_store(): void
    {
        $promotion = Promotion::create(['product_id' => $this->variant->product_id, 'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true]);
        $promotion->variants()->sync([$this->variant->id]); // 50.000 -> 40.000
        Coupon::create(['code' => 'HELENA10', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);

        $admin = $this->person();
        $response = $this->create(['coupon_code' => 'helena10', 'shipping_cop' => 12000], $admin)->assertCreated();
        $order = $this->order($response->json('order.reference'));

        // 2 x 40.000 = 80.000; coupon 10% of the products = 8.000; shipping 12.000.
        $this->assertSame([80000, 8000, 12000, 84000], [$order->subtotal_cop, $order->discount_cop, $order->shipping_cop, $order->total_cop]);
        $this->assertSame(['HELENA10', $promotion->id], [$order->coupon_code, $order->items()->first()->promotion_id]);
        $this->assertSame(1, $order->couponRedemption()->count());
        $this->assertSame('', $order->couponRedemption->email, 'no email, no address stored');
        $this->assertSame(84000, $response->json('order.total_cop'));
        $this->assertSame(8400000, (int) $this->query($response->json('payment.url'))['amount-in-cents']);
    }

    public function test_shipping_defaults_to_to_be_agreed_and_a_manual_value_is_in_the_historial(): void
    {
        $default = $this->order($this->create()->json('order.reference'));
        $this->assertSame(0, $default->shipping_cop);
        $this->assertNull(ActivityLog::where('action', 'created_manual')->first()->changes['shipping_cop'] ?? null);

        $manual = $this->order($this->create(['shipping_cop' => 9500])->json('order.reference'));
        $this->assertSame(9500, $manual->shipping_cop);
        $row = ActivityLog::where('action', 'created_manual')->orderByDesc('id')->first();
        $this->assertSame(['A coordinar', 9500], $row->changes['shipping_cop']);
    }

    public function test_the_stock_goes_down_once_and_a_double_click_does_not_duplicate_the_order(): void
    {
        $admin = $this->person();
        $body = $this->payload();
        $this->actingAs($admin);
        $first = $this->postJson('/admin/orders/manual', $body)->assertCreated();
        $second = $this->postJson('/admin/orders/manual', $body)->assertOk(); // the same key: the same order, with its link

        $this->assertSame($first->json('order.reference'), $second->json('order.reference'));
        $this->assertSame($first->json('payment.url'), $second->json('payment.url'));
        $this->assertSame([1, 1], [Order::count(), Payment::count()]);
        $this->assertSame(8, $this->stock());
        $movements = InventoryMovement::where('order_id', Order::first()->id)->where('type', 'venta')->get();
        $this->assertCount(1, $movements);
        $this->assertSame($admin->id, (int) $movements[0]->user_id, 'the movement says who registered the sale');

        $this->actingAs($this->person())->postJson('/admin/orders/manual', $body)->assertStatus(409); // another person cannot take over a key
    }

    public function test_a_refused_order_is_all_or_nothing_even_with_a_coupon_that_does_not_apply(): void
    {
        $this->create(['coupon_code' => 'NOEXISTE'])->assertStatus(422);
        $this->assertSame([0, 0, 10, 0], [Order::count(), Payment::count(), $this->stock(), Customer::count()]);
    }

    // ---- customers ----

    public function test_the_customer_is_created_and_refreshed_by_email_or_by_phone(): void
    {
        $this->create(['customer_email' => 'laura@example.com', 'marketing_opt_in' => true])->assertCreated();
        $customer = Customer::where('email', 'laura@example.com')->firstOrFail();
        $this->assertSame(['Laura Pérez', '3001234567', 'Cúcuta'], [$customer->name, $customer->phone, $customer->city]);
        $this->assertNotNull($customer->marketing_opt_in_at);

        $this->create(['customer_email' => 'laura@example.com', 'delivery_address' => 'Calle nueva 1', 'customer_name' => 'Laura P. Gómez'])->assertCreated();
        $this->assertSame(1, Customer::count());
        $this->assertSame(['Calle nueva 1', 'Laura P. Gómez'], [$customer->fresh()->last_address, $customer->fresh()->name]);
        $this->assertNotNull($customer->fresh()->marketing_opt_in_at, 'an unticked box never erases a consent');
    }

    public function test_without_an_email_the_customer_is_identified_by_phone_and_completed_later(): void
    {
        $this->create(['customer_phone' => '3109998877'])->assertCreated();
        $this->create(['customer_phone' => '310 999 8877', 'delivery_address' => 'Otra calle 2'])->assertCreated();
        $this->assertSame(1, Customer::count());
        $customer = Customer::first();
        $this->assertNull($customer->email);
        $this->assertSame('Otra calle 2', $customer->last_address);
        $this->assertSame(2, Order::where('customer_id', $customer->id)->count());

        $this->create(['customer_phone' => '3109998877', 'customer_email' => 'nuevo@example.com'])->assertCreated();
        $this->assertSame(1, Customer::count(), 'the customer that only had a phone is completed, not duplicated');
        $this->assertSame('nuevo@example.com', $customer->fresh()->email);

        $this->create(['customer_phone' => '3205550000'])->assertCreated();
        $this->assertSame(2, Customer::count());
    }

    public function test_the_customer_lookup_finds_by_phone_or_email_and_offers_the_last_address(): void
    {
        $this->create(['customer_email' => 'laura@example.com', 'customer_phone' => '3001234567', 'delivery_address' => 'Calle 1 # 2-3'])->assertCreated();
        $admin = $this->person();
        $this->actingAs($admin);

        $this->getJson('/admin/orders/manual/customer?phone=+57 300 123 4567')->assertOk()->assertJsonPath('customer.name', 'Laura Pérez')->assertJsonPath('customer.last_address', 'Calle 1 # 2-3')->assertJsonPath('matched_by', 'phone');
        $this->getJson('/admin/orders/manual/customer?email=LAURA@example.com')->assertOk()->assertJsonPath('customer.phone', '3001234567')->assertJsonPath('matched_by', 'email');
        $this->getJson('/admin/orders/manual/customer?phone=3999999999')->assertOk()->assertJsonPath('customer', null);
        $this->getJson('/admin/orders/manual/customer?phone=123')->assertOk()->assertJsonPath('customer', null);
        $this->getJson('/admin/orders/manual/customer?phone[]=1')->assertStatus(422);
    }

    // ---- the payment link ----

    public function test_the_link_lasts_24_hours_and_so_does_the_deadline(): void
    {
        $response = $this->create(['customer_email' => 'laura@example.com'])->assertCreated();
        $order = $this->order($response->json('order.reference'));
        $payment = $order->payments()->first();
        $query = $this->query($response->json('payment.url'));

        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $payment->checkout_expires_at->timestamp, 5);
        $this->assertSame($payment->checkout_expires_at->timestamp, $order->payment_due_at->timestamp, 'the link expires when the order is cancelled');
        $this->assertSame($payment->checkout_expires_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'), $query['expiration-time']);
        $this->assertSame($payment->checkout_expires_at->toIso8601String(), $response->json('payment.expires_at'));
        $this->assertSame($order->reference.'-1', $query['reference']);
    }

    public function test_the_hours_come_from_the_configuration(): void
    {
        config(['services.wompi.manual_pending_expiry_hours' => 48]);
        $order = $this->order($this->create()->json('order.reference'));
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $order->payment_due_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $order->payments()->first()->checkout_expires_at->timestamp, 5);
    }

    public function test_a_manual_link_only_carries_what_wompi_needs_to_charge(): void
    {
        $with = $this->create(['customer_email' => 'laura@example.com'])->assertCreated()->json('payment.url');
        $without = $this->create()->assertCreated()->json('payment.url');

        $base = ['public-key', 'currency', 'amount-in-cents', 'reference', 'signature:integrity', 'redirect-url', 'expiration-time'];
        $this->assertEqualsCanonicalizing([...$base, 'customer-data:email'], array_keys($this->query($with)));
        $this->assertEqualsCanonicalizing($base, array_keys($this->query($without)));
        $this->assertSame('laura@example.com', $this->query($with)['customer-data:email']);
        $this->assertSame('https://tienda.example.com/pago/resultado', $this->query($with)['redirect-url']);
        foreach ([$with, $without] as $url) {
            foreach (['Laura', 'Pérez', 'Perez', '3001234567', 'Avenida', 'Cúcuta', 'Norte', 'Apto'] as $secret) {
                $this->assertStringNotContainsString($secret, rawurldecode($url));
            }
            $this->assertLessThan(700, strlen($url), 'shorter than the storefront link');
        }
    }

    public function test_the_storefront_link_keeps_sending_the_customer_data_as_before(): void
    {
        $response = $this->postJson('/api/orders', [
            'idempotency_key' => 'web-key-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
        ])->assertCreated();
        $order = $this->order($response->json('order.reference'));
        $this->assertSame(['web', null, null], [$order->channel, $order->created_by, $order->payment_due_at]);

        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => 'maria@example.com'])->assertOk();
        $query = $this->query($this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => 'maria@example.com'])->json('checkout_url'));
        $this->assertSame(['María González', '3001234567', 'Calle 45 # 12-34'], [$query['customer-data:full-name'], $query['customer-data:phone-number'], $query['shipping-address:address-line-1']]);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, Payment::first()->checkout_expires_at->timestamp, 5, 'a storefront link keeps its 30 minutes');
    }

    public function test_the_confirmation_has_the_link_the_deadline_and_the_whatsapp_message(): void
    {
        $response = $this->create()->assertCreated();
        $reference = $response->json('order.reference');
        $url = $response->json('payment.url');
        $message = $response->json('whatsapp.message');

        $this->assertStringStartsWith('Hola Laura Pérez, te escribimos de Café Finca Helena. Este es el link para pagar tu pedido '.$reference.' por $100.000: https://checkout.wompi.co/p/?', $message);
        $this->assertStringContainsString($url, $message);
        $this->assertMatchesRegularExpression('/Tienes hasta \d{1,2} de \w+ de \d{4}, \d{1,2}:\d{2} (am|pm|a\. m\.|p\. m\.) para pagarlo\.$/u', $message);
        $this->assertSame('https://wa.me/573001234567?text='.rawurlencode($message), $response->json('whatsapp.url'));
        $this->assertSame($response->json('payment.expires_label'), \Carbon\Carbon::parse($response->json('payment.expires_at'))->timezone('America/Bogota')->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a'));
    }

    public function test_without_wompi_nothing_is_created(): void
    {
        config(['services.wompi.public_key' => null]);
        $this->create()->assertStatus(503);
        $this->assertSame([0, 0, 10], [Order::count(), Payment::count(), $this->stock()]);

        config(['services.wompi.public_key' => self::KEYS['public_key'], 'app.url' => 'http://localhost:8000']);
        $this->create()->assertStatus(503)->assertJsonPath('code', \App\Exceptions\WompiRedirectUrlException::CODE);
        $this->assertSame([0, 10], [Order::count(), $this->stock()]);
    }

    public function test_the_current_link_can_be_copied_again_and_a_new_one_only_when_it_expired(): void
    {
        $admin = $this->person();
        $created = $this->create([], $admin)->assertCreated();
        $order = $this->order($created->json('order.reference'));

        $this->actingAs($admin)->getJson("/admin/orders/{$order->id}/checkout-link")->assertOk()->assertJsonPath('payment.url', $created->json('payment.url'));
        $this->actingAs($admin)->getJson("/admin/orders/{$order->id}")->assertOk();
        $detail = $this->getJson("/admin/orders/{$order->id}/detail")->assertOk();
        $this->assertSame('valid', $detail->json('order.manual.link'));
        $this->assertSame($admin->name, $detail->json('order.manual.created_by'));

        // Still valid: no second link (two valid links could be paid twice).
        $this->postJson("/admin/orders/{$order->id}/checkout-link")->assertStatus(409);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());

        $this->travel(25)->hours();
        $this->getJson("/admin/orders/{$order->id}/checkout-link")->assertNotFound();
        $this->assertSame('expired', $this->getJson("/admin/orders/{$order->id}/detail")->json('order.manual.link'));

        $renewed = $this->postJson("/admin/orders/{$order->id}/checkout-link")->assertCreated();
        $this->assertNotSame($created->json('payment.url'), $renewed->json('payment.url'));
        $this->assertSame($order->reference.'-2', $this->query($renewed->json('payment.url'))['reference']);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $order->fresh()->payment_due_at->timestamp, 5, 'a new link extends the deadline');
        $this->assertSame(1, ActivityLog::where('action', 'payment_link_created')->count());
        $this->assertSame('valid', $this->getJson("/admin/orders/{$order->id}/detail")->json('order.manual.link'));
        $this->postJson("/admin/orders/{$order->id}/checkout-link")->assertStatus(409);
    }

    public function test_a_new_link_is_refused_for_web_paid_or_in_flight_orders(): void
    {
        $admin = $this->person();
        $web = $this->postJson('/api/orders', [
            'idempotency_key' => 'web-key-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
        ])->json('order.reference');
        $this->actingAs($admin)->postJson('/admin/orders/'.$this->order($web)->id.'/checkout-link')->assertStatus(422);
        $this->getJson('/admin/orders/'.$this->order($web)->id.'/checkout-link')->assertStatus(422);

        $order = $this->order($this->create([], $admin)->json('order.reference'));
        $payment = $order->payments()->first();
        $payment->forceFill(['external_transaction_id' => 'tx-in-flight'])->save(); // the customer is paying right now
        $this->travel(25)->hours();
        $this->actingAs($admin)->postJson("/admin/orders/{$order->id}/checkout-link")->assertStatus(409);

        $payment->forceFill(['external_transaction_id' => null])->save();
        $this->approve($payment);
        $this->actingAs($admin)->postJson("/admin/orders/{$order->id}/checkout-link")->assertStatus(409)->assertJsonPath('paid', true);
        $this->assertSame('none', $this->getJson("/admin/orders/{$order->id}/detail")->json('order.manual.link'));
        $this->postJson('/admin/orders/99999999999999999999/checkout-link')->assertNotFound();
    }

    // ---- Wompi decides ----

    public function test_nothing_marks_the_order_as_paid_but_the_wompi_webhook(): void
    {
        $order = $this->order($this->create(['customer_email' => 'laura@example.com'])->json('order.reference'));
        $this->assertSame(['pending', 'pending'], [$order->status, $order->payment_status]);

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'admin/')) {
                $this->assertDoesNotMatchRegularExpression('/confirm|paid|payment/i', $route->uri());
            }
        }
        $admin = $this->person();
        // Even forging the fields: they are ignored and no extra route exists.
        $this->actingAs($admin)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => 'pending'])->assertStatus(422);
        $this->actingAs($admin)->postJson('/admin/orders/manual', $this->payload(['payment_status' => 'paid', 'status' => 'confirmed', 'paid' => true, 'payment_method' => 'efectivo', 'proof' => 'x']))->assertCreated();
        $this->assertSame(['pending', 'pending'], [Order::orderByDesc('id')->first()->status, Order::orderByDesc('id')->first()->payment_status]);

        $this->approve($order->payments()->first());
        $order->refresh();
        $this->assertSame(['confirmed', 'paid'], [$order->status, $order->payment_status]);
        $this->assertNotNull($order->paid_at);
    }

    public function test_an_unpaid_manual_order_is_not_work_for_dispatch_until_wompi_approves(): void
    {
        config(['shop.require_payment_before_fulfillment' => false]); // even while unpaid store orders may be started
        $admin = $this->person();
        $dispatch = $this->person('dispatch');
        $order = $this->order($this->create(['customer_email' => 'laura@example.com'], $admin)->json('order.reference'));

        $hoy = fn (User $user) => collect($this->actingAs($user)->getJson('/admin/today/data')->json('sections'))->flatten(1)->pluck('reference')->all();
        $this->assertNotContains($order->reference, $hoy($admin));
        $this->assertNotContains($order->reference, $hoy($dispatch));
        $this->assertNotContains($order->reference, collect($this->actingAs($dispatch)->getJson('/admin/orders/data')->json('data'))->pluck('reference')->all());
        $this->assertContains($order->reference, collect($this->actingAs($admin)->getJson('/admin/orders/data')->json('data'))->pluck('reference')->all()); // the admin sees it in Pedidos
        $this->actingAs($dispatch)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => 'pending'])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => 'pending'])->assertStatus(422);
        $this->assertNull($this->actingAs($admin)->getJson("/admin/orders/{$order->id}/detail")->json('next'));

        $this->approve($order->payments()->first());
        $this->assertContains($order->reference, $hoy($admin));
        $this->assertContains($order->reference, $hoy($dispatch));
        $card = collect($this->actingAs($dispatch)->getJson('/admin/today/data')->json('sections.new'))->firstWhere('reference', $order->reference);
        $this->assertSame('manual', $card['channel']);
        $this->assertArrayNotHasKey('customer_email', $card);
        $this->assertArrayNotHasKey('total_cop', $card);
        $this->actingAs($dispatch)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => 'confirmed'])->assertOk();
    }

    public function test_when_wompi_approves_the_customer_gets_the_receipt_only_with_an_email_and_the_admin_is_told(): void
    {
        $withEmail = $this->order($this->create(['customer_email' => 'laura@example.com'])->json('order.reference'));
        $without = $this->order($this->create(['customer_phone' => '3112223344'])->json('order.reference'));

        $this->approve($withEmail->payments()->first());
        $this->approve($without->payments()->first());

        $recipients = fn (Order $order) => OrderNotification::where('order_id', $order->id)->orderBy('type')->pluck('recipient', 'type')->all();
        $this->assertSame(['admin_new_paid' => 'dueno@tienda.example.com', 'customer_paid' => 'laura@example.com'], $recipients($withEmail));
        $this->assertSame(['admin_new_paid' => 'dueno@tienda.example.com'], $recipients($without));
        $this->assertSame(['confirmed', 'paid'], [$without->fresh()->status, $without->fresh()->payment_status]);
    }

    // ---- the expiry ----

    private function fakeWompiEmpty(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/v1/transactions?') ? Http::response(['data' => []]) : Http::response([], 404));
    }

    public function test_the_expiry_command_respects_each_orders_own_deadline(): void
    {
        $this->fakeWompiEmpty();
        $manual = $this->order($this->create()->json('order.reference'));
        $web = $this->postJson('/api/orders', [
            'idempotency_key' => 'web-key-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
        ])->json('order.reference');
        $web = $this->order($web);
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $web->reference, 'customer_email' => 'maria@example.com'])->assertOk();

        $this->travel(2)->hours(); // the storefront order is past its 60 minutes, the manual one has 22 hours left
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $web->fresh()->status);
        $this->assertSame('pending', $manual->fresh()->status);
        $this->assertSame(8, $this->stock()); // 10 - 2 (manual) - 1 (web) + 1 given back by the cancelled storefront order

        $this->travel(21)->hours(); // 23 h: still inside the 24 h
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('pending', $manual->fresh()->status);

        $this->travel(2)->hours(); // 25 h
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $manual->refresh();
        $this->assertSame('cancelled', $manual->status);
        $this->assertSame(10, $this->stock());
        $this->assertStringContainsString('venció el plazo de pago', \App\Models\OrderStatusHistory::where('order_id', $manual->id)->value('note'));
    }

    public function test_a_payment_still_in_process_keeps_the_manual_order_alive_until_24_hours_after_its_deadline(): void
    {
        $order = $this->order($this->create()->json('order.reference'));
        $payment = $order->payments()->first();
        $payment->forceFill(['external_transaction_id' => 'tx-pse-1'])->save();
        Http::fake(fn ($request) => preg_match('#/v1/transactions/tx-pse-1$#', $request->url()) ? Http::response(['data' => $this->tx($payment, 'PENDING') + []]) : Http::response([], 404));

        $this->travel(26)->hours(); // past the 24 h deadline, PSE still pending
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status);

        $this->travel(23)->hours(); // 49 h after the order = 25 h after the deadline
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ---- the form's data ----

    public function test_the_product_list_shows_published_variants_with_promotion_and_stock(): void
    {
        $promotion = Promotion::create(['product_id' => $this->variant->product_id, 'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true]);
        $promotion->variants()->sync([$this->variant->id]);
        ProductVariant::create(['product_id' => $this->variant->product_id, 'sku' => 'FH-009', 'name' => 'Oculto', 'price_cop' => 1000, 'is_published' => false]);
        $this->actingAs($this->person());

        $rows = collect($this->getJson('/admin/orders/manual/products')->assertOk()->json('data'))->keyBy('sku');
        $this->assertEqualsCanonicalizing(['FH-001', 'FH-002'], $rows->keys()->all());
        $this->assertSame([40000, 50000, '-20%', 10, 'Café Premium', 250, 'Molido'], [$rows['FH-001']['price_cop'], $rows['FH-001']['original_price_cop'], $rows['FH-001']['discount_label'], $rows['FH-001']['available_quantity'], $rows['FH-001']['product_name'], $rows['FH-001']['weight_grams'], $rows['FH-001']['grind_type']]);
        $this->assertSame([90000, null, 0], [$rows['FH-002']['price_cop'], $rows['FH-002']['original_price_cop'], $rows['FH-002']['available_quantity']]); // sold out stays listed

        $this->assertSame(['FH-002'], collect($this->getJson('/admin/orders/manual/products?search=500')->json('data'))->pluck('sku')->all());
        $this->assertSame(['FH-001'], collect($this->getJson('/admin/orders/manual/products?search=fh-001')->json('data'))->pluck('sku')->all());
        $this->getJson('/admin/orders/manual/products?search[]=x')->assertStatus(422);
    }

    public function test_the_quote_is_the_stores_quote_plus_the_manual_shipping(): void
    {
        Coupon::create(['code' => 'HELENA10', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        $items = [['variant_id' => $this->variant->id, 'quantity' => 2]];
        $store = $this->postJson('/api/orders/quote', ['items' => $items, 'coupon_code' => 'HELENA10'])->json('data');
        $this->actingAs($this->person());
        $manual = $this->postJson('/admin/orders/manual/quote', ['items' => $items, 'coupon_code' => 'HELENA10'])->assertOk()->json('data');

        $this->assertSame($store, $manual);
        $this->assertTrue($manual['shipping_pending']);
        $withShipping = $this->postJson('/admin/orders/manual/quote', ['items' => $items, 'coupon_code' => 'HELENA10', 'shipping_cop' => 7000])->json('data');
        $this->assertSame([7000, false, 97000, 'HELENA10'], [$withShipping['shipping_cop'], $withShipping['shipping_pending'], $withShipping['total_cop'], $withShipping['coupon']['code']]);

        $bad = $this->postJson('/admin/orders/manual/quote', ['items' => $items, 'coupon_code' => 'NOEXISTE'])->assertOk()->json('data');
        $this->assertNotNull($bad['coupon_error']);
        $this->assertNull($bad['coupon']);
        $this->postJson('/admin/orders/manual/quote', ['items' => [['variant_id' => $this->variant->id, 'quantity' => 11]]])->assertOk()->assertJsonPath('data.items.0.insufficient', true);
        $this->postJson('/admin/orders/manual/quote', ['items' => $items, 'shipping_cop' => -5])->assertStatus(422);
    }

    // ---- the lists ----

    public function test_pedidos_filters_by_channel_and_every_row_carries_it(): void
    {
        $admin = $this->person();
        $manual = $this->order($this->create([], $admin)->json('order.reference'));
        $web = $this->order($this->postJson('/api/orders', [
            'idempotency_key' => 'web-key-0123456789abcdef', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
        ])->json('order.reference'));
        $this->actingAs($admin);

        $all = collect($this->getJson('/admin/orders/data')->json('data'))->pluck('channel', 'reference');
        $this->assertSame(['manual', 'web'], [$all[$manual->reference], $all[$web->reference]]);
        $this->assertSame([$manual->reference], collect($this->getJson('/admin/orders/data?channel=manual')->json('data'))->pluck('reference')->all());
        $this->assertSame([$web->reference], collect($this->getJson('/admin/orders/data?channel=web')->json('data'))->pluck('reference')->all());
        $this->getJson('/admin/orders/data?channel=otro')->assertStatus(422);
    }

    public function test_the_storefront_checkout_still_requires_an_email_and_the_terms(): void
    {
        $base = [
            'idempotency_key' => 'web-key-0123456789abcdef', 'customer_name' => 'María González', 'customer_phone' => '3001234567', 'delivery_department' => 'Cundinamarca',
            'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true, 'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
        ];
        $this->postJson('/api/orders', $base)->assertStatus(422)->assertJsonValidationErrors('customer_email');
        $this->postJson('/api/orders', ['customer_email' => ''] + $base)->assertStatus(422)->assertJsonValidationErrors('customer_email');
        $this->postJson('/api/orders', ['customer_email' => 'maria@example.com', 'terms_accepted' => false] + $base)->assertStatus(422)->assertJsonValidationErrors('terms_accepted');
        $this->postJson('/api/orders', ['customer_email' => 'maria@example.com', 'shipping_cop' => 1, 'channel' => 'manual'] + $base)->assertCreated();
        $order = Order::first();
        $this->assertSame(['web', 0, null], [$order->channel, $order->shipping_cop, $order->created_by], 'the storefront cannot set the shipping or the channel');
    }

    // ---- the Historial ----

    public function test_the_historial_records_the_order_without_personal_data(): void
    {
        $admin = $this->person();
        $reference = $this->create(['customer_email' => 'laura@example.com', 'shipping_cop' => 5000], $admin)->json('order.reference');

        $row = ActivityLog::where('action', 'created_manual')->firstOrFail();
        $this->assertSame(['orders', $admin->id], [$row->module, (int) $row->user_id]);
        $this->assertSame('manual', $row->context['channel']);
        $this->assertStringContainsString($reference, (string) $row->subject_label);
        $dump = json_encode(ActivityLog::all()->toArray());
        foreach (['laura@example.com', 'Laura', '3001234567', 'Avenida', 'wompi.co', 'signature'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_a_manual_order_without_email_does_not_break_the_pages_that_read_the_email(): void
    {
        $order = $this->order($this->create(['customer_phone' => '3112223344'])->json('order.reference'));
        $admin = $this->person();
        $this->actingAs($admin);

        $this->getJson("/admin/orders/{$order->id}/detail")->assertOk()->assertJsonPath('order.customer_email', null);
        $this->getJson('/admin/orders/data?search=3112223344')->assertOk();
        $this->getJson('/admin/customers/data')->assertOk();
        $this->getJson('/admin/customers/'.$order->customer_id.'/detail')->assertNotFound(); // listed only once Wompi approves its first order
        $this->approve($order->payments()->first());
        $this->getJson('/admin/customers/data')->assertOk()->assertJsonPath('data.0.email', null);
        $this->getJson('/admin/customers/'.$order->customer_id.'/detail')->assertOk();
        $this->getJson('/admin/dashboard/summary')->assertOk();
        $this->getJson('/admin/emails/preview/customer_paid?order='.$order->id)->assertOk();
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => 'cualquiera@example.com'])->assertStatus(422); // nobody can prove ownership with an email
        $this->postJson('/api/orders/pending/status', ['reference' => $order->reference, 'customer_email' => 'cualquiera@example.com'])->assertOk()->assertJsonPath('state', 'unknown');
    }
}
