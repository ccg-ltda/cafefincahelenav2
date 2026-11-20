<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\WompiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Wompi payments with simulated Wompi answers (Http::fake): nothing here touches the network. The keys are obviously fake.
 */
class WompiTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'env' => 'sandbox', 'public_key' => 'pub_test_FAKEPUBLICKEY0000000000000000', 'private_key' => 'prv_test_FAKEPRIVATEKEY000000000000000',
        'integrity_secret' => 'test_integrity_FAKEINTEGRITY000000000000', 'events_secret' => 'test_events_FAKEEVENTS000000000000000',
    ];

    private static int $n = 0;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wompi' => self::KEYS + ['checkout_window_minutes' => 30, 'pending_expiry_minutes' => 60], 'app.url' => 'https://tienda.example.com']); // a public APP_URL, as in production
        RateLimiter::clear('payment-attempt');
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Café Premium', 'slug' => 'cafe-premium', 'is_published' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g', 'price_cop' => 50000, 'is_published' => true]);
        $this->variant->forceFill(['stock_quantity' => 10])->save();
    }

    // ---- helpers ----

    private function person(string $role = 'admin'): User
    {
        $n = ++self::$n;

        return User::factory()->create(['role' => $role, 'name' => "Persona $role $n", 'username' => "$role.wompi$n", 'email' => "$role.wompi$n@example.com"]);
    }

    /** A real order through the public API (prices, stock and coupon as the checkout does it). Total: 2 x 50.000 = 100.000. */
    private function placeOrder(?string $coupon = null, string $key = 'wompi-key-0123456789abcdef'): Order
    {
        $response = $this->postJson('/api/orders', [
            'idempotency_key' => $key, 'customer_name' => 'María González', 'customer_email' => 'maria.gonzalez@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'coupon_code' => $coupon, 'items' => [['variant_id' => $this->variant->id, 'quantity' => 2]],
        ])->assertSuccessful();

        return Order::where('reference', $response->json('order.reference'))->firstOrFail();
    }

    private function attempt(Order $order): Payment
    {
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => $order->customer_email])->assertOk();

        return Payment::where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
    }

    /** A transaction as Wompi reports it. */
    private function tx(Payment $payment, string $status = 'APPROVED', array $override = []): array
    {
        return $override + ['id' => '01-1531231271-'.$payment->id, 'status' => $status, 'reference' => $payment->reference, 'amount_in_cents' => $payment->amount_cop * 100,
            'currency' => 'COP', 'payment_method_type' => 'CARD', 'status_message' => $status === 'DECLINED' ? 'Fondos insuficientes' : null];
    }

    private function event(array $transaction, array $override = []): array
    {
        $timestamp = 1530291411;
        $checksum = strtoupper(hash('sha256', $transaction['id'].$transaction['status'].$transaction['amount_in_cents'].$timestamp.self::KEYS['events_secret']));

        return $override + ['event' => 'transaction.updated', 'data' => ['transaction' => $transaction], 'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => $checksum], 'timestamp' => $timestamp, 'sent_at' => '2026-11-20T16:45:05.000Z'];
    }

    /** @var array<string, array<string, mixed>> */
    private array $wompiById = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $wompiByReference = [];

    private int $wompiStatus = 200;

    private bool $wompiFaked = false;

    /** Wompi's API answers by transaction id (and by reference); anything else is a 404. Calling it again changes the answers. */
    private function fakeWompi(array $byId = [], array $byReference = [], int $status = 200): void
    {
        [$this->wompiById, $this->wompiByReference, $this->wompiStatus] = [$byId, $byReference, $status];
        if ($this->wompiFaked) {
            return;
        }
        $this->wompiFaked = true;
        Http::fake(function ($request) {
            if ($this->wompiStatus !== 200) {
                return Http::response([], $this->wompiStatus);
            }
            if (preg_match('#/v1/transactions/([^/?]+)$#', $request->url(), $found)) {
                $id = urldecode($found[1]);

                return isset($this->wompiById[$id]) ? Http::response(['data' => $this->wompiById[$id]]) : Http::response(['error' => 'not found'], 404);
            }
            if (str_contains($request->url(), '/v1/transactions?')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['data' => $this->wompiByReference[$query['reference']] ?? []]);
            }

            return Http::response([], 404);
        });
    }

    private function stock(): int
    {
        return (int) $this->variant->fresh()->stock_quantity;
    }

    // ---- signatures (the formulas of the official docs) ----

    public function test_the_integrity_signature_matches_the_documented_example(): void
    {
        // docs.wompi.co/docs/colombia/widget-checkout-web, "Paso 3": the example of the docs (a public, fake secret).
        config(['services.wompi.integrity_secret' => 'prod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6']);
        $this->assertSame('37c8407747e595535433ef8f6a811d853cd943046624a0ec04662b17bbf33bf5', app(WompiService::class)->integritySignature('sk8-438k4-xmxm392-sn2m', 2490000, 'COP'));
        $this->assertSame(hash('sha256', 'sk8-438k4-xmxm392-sn2m2490000COP2023-06-09T20:28:50.000Zprod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6'),
            app(WompiService::class)->integritySignature('sk8-438k4-xmxm392-sn2m', 2490000, 'COP', '2023-06-09T20:28:50.000Z'));
    }

    public function test_the_event_checksum_follows_the_documented_algorithm(): void
    {
        // docs.wompi.co/docs/colombia/eventos: SHA256 of the values of signature.properties (read from data, in that order), then
        // the timestamp, then the events secret. (The sample hash printed in the docs does not come out of its own sample inputs,
        // so the algorithm is checked with an independent computation; the sandbox test of docs/wompi-sandbox.md confirms it with real events.)
        config(['services.wompi.events_secret' => 'test_events_UnSecretoDePrueba']);
        $payload = ['event' => 'transaction.updated', 'data' => ['transaction' => ['id' => '1234-1610641025-49201', 'status' => 'APPROVED', 'amount_in_cents' => 4490000]],
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''], 'timestamp' => 1530291411];
        $expected = hash('sha256', '1234-1610641025-49201APPROVED4490000'.'1530291411'.'test_events_UnSecretoDePrueba');

        $this->assertSame($expected, app(WompiService::class)->eventChecksum($payload));
        $payload['signature']['checksum'] = strtoupper($expected); // Wompi prints it in uppercase hex
        $this->assertTrue(app(WompiService::class)->validEvent($payload));
        $this->assertTrue(app(WompiService::class)->validEvent($payload, strtolower($expected)));
        $payload['data']['transaction']['status'] = 'DECLINED';
        $this->assertFalse(app(WompiService::class)->validEvent($payload));
        // The list of properties can change: another list is followed, not a fixed one.
        $payload['data']['transaction']['status'] = 'APPROVED';
        $payload['signature']['properties'] = ['transaction.id', 'transaction.status'];
        $this->assertFalse(app(WompiService::class)->validEvent($payload));
        $payload['signature']['checksum'] = hash('sha256', '1234-1610641025-49201APPROVED'.'1530291411'.'test_events_UnSecretoDePrueba');
        $this->assertTrue(app(WompiService::class)->validEvent($payload));
    }

    public function test_payments_are_enabled_only_with_every_key_of_the_configured_environment(): void
    {
        $this->assertTrue(app(WompiService::class)->enabled());
        foreach (['public_key', 'private_key', 'integrity_secret', 'events_secret'] as $key) {
            config(['services.wompi' => array_merge(self::KEYS, [$key => ''])]);
            $this->assertFalse(app(WompiService::class)->enabled(), "$key empty");
            config(['services.wompi' => array_merge(self::KEYS, [$key => 'prod_'.$key])]);
            $this->assertFalse(app(WompiService::class)->enabled(), "$key from another environment");
        }
        config(['services.wompi' => array_merge(self::KEYS, ['env' => 'staging'])]);
        $this->assertFalse(app(WompiService::class)->enabled());
    }

    // ---- the attempt ----

    public function test_an_attempt_answers_the_checkout_url_with_a_server_side_signature_and_no_secret(): void
    {
        $order = $this->placeOrder();
        $response = $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => 'MARIA.GONZALEZ@example.com'])->assertOk();

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame([$order->reference.'-1', 'wompi', 'pending', 100000, 'COP'], [$payment->reference, $payment->provider, $payment->status, $payment->amount_cop, $payment->currency]);
        $this->assertTrue($payment->checkout_expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));

        $url = $response->json('checkout_url');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $expiration = $payment->checkout_expires_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');
        $this->assertSame(self::KEYS['public_key'], $query['public-key']);
        $this->assertSame(['COP', '10000000', $payment->reference, $expiration, 'https://tienda.example.com/pago/resultado'], [$query['currency'], $query['amount-in-cents'], $query['reference'], $query['expiration-time'], $query['redirect-url']]);
        $this->assertSame(hash('sha256', $payment->reference.'10000000COP'.$expiration.self::KEYS['integrity_secret']), $query['signature:integrity']);
        $this->assertSame(['maria.gonzalez@example.com', 'María González', '3001234567', '+57'], [$query['customer-data:email'], $query['customer-data:full-name'], $query['customer-data:phone-number'], $query['customer-data:phone-number-prefix']]);
        $this->assertSame(['Calle 45 # 12-34', 'CO', 'Bogotá', 'Cundinamarca'], [$query['shipping-address:address-line-1'], $query['shipping-address:country'], $query['shipping-address:city'], $query['shipping-address:region']]);
        foreach (['integrity_secret', 'events_secret', 'private_key'] as $secret) {
            $this->assertStringNotContainsString(self::KEYS[$secret], $response->getContent());
        }
    }

    public function test_the_attempt_does_not_reveal_whether_an_order_exists_or_let_anybody_else_pay_for_it(): void
    {
        $order = $this->placeOrder();
        $wrongEmail = $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => 'otra.persona@example.com']);
        $unknown = $this->postJson('/api/payments/wompi/attempt', ['reference' => 'CFH-NOEXISTE0000', 'customer_email' => 'maria.gonzalez@example.com']);

        $wrongEmail->assertStatus(422);
        $this->assertSame($unknown->status(), $wrongEmail->status());
        $this->assertSame($unknown->json(), $wrongEmail->json()); // same body: nothing to tell them apart
        $this->assertSame(0, Payment::count());
        $this->postJson('/api/payments/wompi/attempt', [])->assertStatus(422);
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference])->assertStatus(422); // the email is mandatory
    }

    public function test_the_attempt_has_a_rate_limit(): void
    {
        $order = $this->placeOrder();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => 'x@example.com'])->assertStatus(422);
        }
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => $order->customer_email])->assertStatus(429);
    }

    public function test_pressing_the_button_twice_reuses_the_open_attempt_and_an_expired_one_gets_the_next_reference(): void
    {
        $order = $this->placeOrder();
        $first = $this->attempt($order);
        $second = $this->attempt($order);
        $this->assertSame($first->id, $second->id);

        $this->travel(31)->minutes();
        $third = $this->attempt($order);
        $this->assertSame([$order->reference.'-2', 2], [$third->reference, Payment::where('order_id', $order->id)->count()]);
    }

    public function test_without_valid_keys_there_is_no_payment_and_the_page_says_so(): void
    {
        $order = $this->placeOrder();
        $this->get('/')->assertSee('name="shop-payments" content="1"', false);
        config(['services.wompi.private_key' => '']);

        $this->get('/')->assertSee('name="shop-payments" content="0"', false);
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => $order->customer_email])->assertStatus(503);
        $this->getJson('/api/payments/wompi/result?id=01-1531231271-1')->assertNotFound();
        $this->get('/pago/resultado?id=x')->assertOk()->assertSee('noindex', false);
    }

    public function test_an_already_paid_order_cannot_start_another_attempt(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        app(PaymentService::class)->applyTransaction($payment, $this->tx($payment));

        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => $order->customer_email])->assertStatus(409)->assertJsonPath('paid', true);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    // ---- the redirect-url and the size of the checkout URL (Wompi's firewall answers 403 to a local redirect-url and to huge URLs) ----

    public function test_a_local_app_url_is_refused_with_a_clear_message_and_a_public_one_passes(): void
    {
        $wompi = app(WompiService::class);

        foreach (['http://127.0.0.1:8000', 'https://127.0.0.1', 'http://localhost', 'http://localhost:8000', 'https://localhost', 'http://foo.localhost', 'http://192.168.1.10',
            'http://10.0.0.5', 'http://172.16.0.9', 'http://169.254.1.1', 'http://[::1]:8000', ''] as $local) {
            config(['app.url' => $local]);
            try {
                $wompi->redirectUrl();
                $this->fail("$local should be refused");
            } catch (\App\Exceptions\WompiRedirectUrlException $error) {
                $this->assertStringContainsString('APP_URL', $error->getMessage());
                $this->assertStringContainsString('config:clear', $error->getMessage());
            }
        }
        foreach (['https://tienda.example.com' => 'https://tienda.example.com/pago/resultado', 'http://example.com/' => 'http://example.com/pago/resultado',
            'https://abc-123.ngrok-free.app' => 'https://abc-123.ngrok-free.app/pago/resultado', 'https://cafefincahelena.com:8443' => 'https://cafefincahelena.com:8443/pago/resultado'] as $public => $expected) {
            config(['app.url' => $public]);
            $this->assertSame($expected, $wompi->redirectUrl());
        }
    }

    public function test_the_attempt_with_a_local_app_url_answers_a_clear_error_and_creates_nothing(): void
    {
        $order = $this->placeOrder();
        config(['app.url' => 'http://127.0.0.1:8000']);

        $response = $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => $order->customer_email]);

        $response->assertStatus(503)->assertJsonPath('code', 'invalid_redirect_url');
        $this->assertStringContainsString('APP_URL', $response->json('message'));
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent()); // the message does not repeat the address
        $this->assertSame(0, Payment::count()); // no attempt was created
        $this->assertSame(['pending', 8], [$order->fresh()->status, $this->stock()]); // the order is intact: the retry will not duplicate anything

        config(['app.url' => 'https://tienda.example.com']);
        $this->postJson('/api/payments/wompi/attempt', ['reference' => $order->reference, 'customer_email' => $order->customer_email])->assertOk();
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Order::count());
    }

    public function test_the_checkout_url_limits_the_address_lines_and_drops_the_second_one_when_it_would_be_too_long(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $query = fn (string $url) => (function () use ($url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $parsed);

            return $parsed;
        })();
        $wompi = app(WompiService::class);

        // Normal: both lines go, "Calle 128 # 53 a 23" and the details exactly as written.
        $order->forceFill(['delivery_address' => 'Calle 128 # 53 a 23', 'delivery_details' => 'Apto 502, torre 2'])->save();
        $url = $wompi->checkoutUrl($payment, $order->fresh());
        $this->assertSame(['Calle 128 # 53 a 23', 'Apto 502, torre 2'], [$query($url)['shipping-address:address-line-1'], $query($url)['shipping-address:address-line-2']]);
        $this->assertLessThanOrEqual(1800, strlen($url));

        // Each line is cut to 120 characters.
        $order->forceFill(['delivery_address' => str_repeat('Carrera 7', 40), 'delivery_details' => str_repeat('Torre', 40)])->save();
        $url = $wompi->checkoutUrl($payment, $order->fresh());
        $this->assertSame([120, 120], [mb_strlen($query($url)['shipping-address:address-line-1']), mb_strlen($query($url)['shipping-address:address-line-2'])]);

        // The cut never leaves a trailing space: "Carrera 7 " x 40 is cut exactly after a space (position 120), which is trimmed, so 119.
        $order->forceFill(['delivery_address' => str_repeat('Carrera 7 ', 40), 'delivery_details' => null])->save();
        $this->assertSame(str_repeat('Carrera 7 ', 11).'Carrera 7', $query($wompi->checkoutUrl($payment, $order->fresh()))['shipping-address:address-line-1']);

        // Accents inflate the encoded URL (each one becomes 6 characters): when it passes ~1800 only the second line is left out, the rest stays.
        // Only line 2 can go, so the fixture is sized for that: line 1 (100 x "ñ") fits, line 1 + line 2 (+ 120 x "ñ") does not.
        $order->forceFill(['delivery_address' => str_repeat('ñ', 100), 'delivery_details' => str_repeat('ñ', 120)])->save();
        $url = $wompi->checkoutUrl($payment, $order->fresh());
        $this->assertLessThanOrEqual(1800, strlen($url));
        $this->assertArrayNotHasKey('shipping-address:address-line-2', $query($url));
        $this->assertSame(100, mb_strlen($query($url)['shipping-address:address-line-1']));
        $order->forceFill(['delivery_details' => str_repeat('ñ', 20)])->save(); // a short second line still fits: it is the length that decides, not the accents
        $this->assertArrayHasKey('shipping-address:address-line-2', $query($wompi->checkoutUrl($payment, $order->fresh())));
        $this->assertSame(['CO', self::KEYS['public_key']], [$query($url)['shipping-address:country'], $query($url)['public-key']]);

        // Without details there is no second line at all.
        $order->forceFill(['delivery_address' => 'Calle 1 # 2-3', 'delivery_details' => null])->save();
        $this->assertArrayNotHasKey('shipping-address:address-line-2', $query($wompi->checkoutUrl($payment, $order->fresh())));
    }

    // ---- the webhook ----

    public function test_a_valid_approved_event_marks_the_order_paid_and_fires_the_events_once(): void
    {
        Event::fake([OrderPaid::class, OrderStatusChanged::class]);
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $event = $this->event($this->tx($payment));

        $this->postJson('/api/payments/wompi/events', $event, ['X-Event-Checksum' => $event['signature']['checksum']])->assertOk()->assertJsonPath('received', true);

        $order->refresh();
        $payment->refresh();
        $this->assertSame(['confirmed', 'paid'], [$order->status, $order->payment_status]);
        $this->assertSame(['approved', 'CARD'], [$payment->status, $payment->payment_method_type]);
        $this->assertNotNull($payment->verified_at);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertSame('01-1531231271-'.$payment->id, $payment->external_transaction_id);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(OrderStatusChanged::class, fn ($e) => [$e->from, $e->to, $e->by] === ['pending', 'confirmed', null]);
        $this->assertSame('applied', PaymentEvent::firstOrFail()->outcome);
    }

    public function test_an_invalid_checksum_is_a_401_and_changes_nothing(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $event = $this->event($this->tx($payment));

        $tampered = $event;
        $tampered['data']['transaction']['status'] = 'APPROVED';
        $tampered['signature']['checksum'] = str_repeat('A', 64);
        $this->postJson('/api/payments/wompi/events', $tampered)->assertStatus(401);

        $wrongSecret = $event;
        $wrongSecret['signature']['checksum'] = strtoupper(hash('sha256', $payment->external_transaction_id.'APPROVED'.($payment->amount_cop * 100).$event['timestamp'].'otro-secreto'));
        $this->postJson('/api/payments/wompi/events', $wrongSecret)->assertStatus(401);

        $this->postJson('/api/payments/wompi/events', $event, ['X-Event-Checksum' => str_repeat('B', 64)])->assertStatus(401); // the header must agree too
        $this->postJson('/api/payments/wompi/events', ['event' => 'transaction.updated'])->assertStatus(401);

        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->assertSame(0, PaymentEvent::count());
        $this->assertStringNotContainsString(self::KEYS['events_secret'], $this->postJson('/api/payments/wompi/events', $tampered)->getContent());
    }

    public function test_the_same_event_twice_changes_nothing_the_second_time(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $event = $this->event($this->tx($payment));

        $this->postJson('/api/payments/wompi/events', $event)->assertOk();
        $this->postJson('/api/payments/wompi/events', $event)->assertOk()->assertJsonPath('duplicate', true);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame(1, PaymentEvent::count());
        $this->assertSame(1, \App\Models\OrderStatusHistory::where('order_id', $order->id)->count());
        // Even if Wompi sends the same news with a different checksum (a new timestamp), the payment is not applied twice.
        $again = $this->event($this->tx($payment), ['timestamp' => 1530299999]);
        $again['signature']['checksum'] = strtoupper(hash('sha256', $payment->fresh()->external_transaction_id.'APPROVED'.($payment->amount_cop * 100).'1530299999'.self::KEYS['events_secret']));
        $this->postJson('/api/payments/wompi/events', $again)->assertOk();
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame(1, \App\Models\OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_an_event_whose_amount_or_reference_does_not_match_changes_nothing(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment, 'APPROVED', ['amount_in_cents' => 100])))->assertOk();
        $this->assertSame('mismatch', PaymentEvent::latest('id')->first()->outcome);
        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment, 'APPROVED', ['reference' => 'CFH-OTRAREFERENCIA-1', 'id' => 'otra-tx-1'])))->assertOk();
        $this->assertSame('unknown_reference', PaymentEvent::latest('id')->first()->outcome);
        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment, 'APPROVED', ['currency' => 'USD', 'id' => 'otra-tx-2'])))->assertOk();
        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment), ['environment' => 'prod']))->assertOk(); // another environment

        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verified_at);
    }

    public function test_a_declined_event_records_the_attempt_and_keeps_the_order_alive(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment, 'DECLINED')))->assertOk();

        $payment->refresh();
        $this->assertSame(['declined', 'Fondos insuficientes', null], [$payment->status, $payment->status_message, $payment->verified_at]);
        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]); // not cancelled
        $this->assertSame(8, $this->stock());
    }

    public function test_unknown_events_and_other_event_types_are_answered_200_and_ignored(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $other = $this->event($this->tx($payment), ['event' => 'nequi_token.updated']);

        $this->postJson('/api/payments/wompi/events', $other)->assertOk();
        $this->assertSame('ignored', PaymentEvent::firstOrFail()->outcome);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_closing_the_browser_does_not_matter_the_webhook_alone_pays_the_order(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order); // the customer paid at Wompi and never came back to /pago/resultado

        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment, 'APPROVED', ['payment_method_type' => 'NEQUI'])))->assertOk();

        $this->assertSame(['confirmed', 'paid'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->assertSame('NEQUI', $payment->fresh()->payment_method_type);
    }

    // ---- the result page ----

    public function test_the_result_page_asks_wompi_and_never_trusts_the_url(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $transaction = $this->tx($payment, 'DECLINED');
        $this->fakeWompi([$transaction['id'] => $transaction]);

        // The URL claims "APPROVED"; Wompi says DECLINED: Wompi wins.
        $response = $this->getJson('/api/payments/wompi/result?id='.$transaction['id'].'&status=APPROVED&reference='.$order->reference)->assertOk();

        $response->assertJsonPath('state', 'declined')->assertJsonPath('message', 'Fondos insuficientes')->assertJsonPath('can_retry', true);
        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->assertSame('declined', $payment->fresh()->status);
    }

    public function test_an_approved_result_pays_the_order_and_shows_only_what_the_customer_needs(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $transaction = $this->tx($payment);
        $this->fakeWompi([$transaction['id'] => $transaction]);

        $response = $this->getJson('/api/payments/wompi/result?id='.$transaction['id'])->assertOk();

        $response->assertJsonPath('state', 'approved')->assertJsonPath('order.reference', $order->reference)->assertJsonPath('order.total_cop', 100000)->assertJsonPath('order.items.0.quantity', 2);
        $response->assertJsonPath('order.email', 'm***@example.com');
        foreach (['maria.gonzalez', 'Calle 45', '3001234567', 'María', 'customer_phone', 'delivery_address'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent(), "the result exposes $private");
        }
        $this->assertSame(['confirmed', 'paid'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->getJson('/api/payments/wompi/result?id='.$transaction['id'])->assertOk()->assertJsonPath('state', 'approved'); // reloading the page
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    public function test_a_pending_result_waits_and_a_later_approval_resolves_it(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $pending = $this->tx($payment, 'PENDING', ['payment_method_type' => 'PSE']);
        $this->fakeWompi([$pending['id'] => $pending]);
        $this->getJson('/api/payments/wompi/result?id='.$pending['id'])->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('can_retry', false);
        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]);

        $approved = $pending;
        $approved['status'] = 'APPROVED';
        $this->fakeWompi([$approved['id'] => $approved]);
        $this->getJson('/api/payments/wompi/result?id='.$approved['id'])->assertOk()->assertJsonPath('state', 'approved');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_the_result_never_exposes_another_order_or_an_amount_that_does_not_match(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $foreign = ['id' => 'ajena-0001', 'status' => 'APPROVED', 'reference' => 'OTRA-TIENDA-77', 'amount_in_cents' => 5000, 'currency' => 'COP'];
        $mismatch = $this->tx($payment, 'APPROVED', ['amount_in_cents' => 1000]);
        $this->fakeWompi(['ajena-0001' => $foreign, $mismatch['id'] => $mismatch]);

        $this->getJson('/api/payments/wompi/result?id=ajena-0001')->assertNotFound()->assertJsonPath('state', 'unknown');
        $this->getJson('/api/payments/wompi/result?id=no-existe-000')->assertNotFound()->assertJsonPath('state', 'unknown');
        $this->getJson('/api/payments/wompi/result?id=../../etc')->assertNotFound();
        $this->getJson('/api/payments/wompi/result')->assertNotFound();
        // The transaction is ours but the amount is not: nothing is applied and nothing is called "approved".
        $this->getJson('/api/payments/wompi/result?id='.$mismatch['id'])->assertOk()->assertJsonPath('state', 'pending');
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_when_wompi_cannot_be_asked_the_result_says_so_and_changes_nothing(): void
    {
        $order = $this->placeOrder();
        $this->attempt($order);
        $this->fakeWompi(status: 500);

        $this->getJson('/api/payments/wompi/result?id=01-1531231271-1')->assertStatus(503)->assertJsonPath('state', 'unavailable');
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    // ---- retry after a rejection ----

    public function test_a_retry_after_a_rejection_reuses_the_same_order_without_touching_stock(): void
    {
        $order = $this->placeOrder();
        $first = $this->attempt($order);
        $declined = $this->tx($first, 'DECLINED');
        $this->fakeWompi([$declined['id'] => $declined]);
        $this->getJson('/api/payments/wompi/result?id='.$declined['id'])->assertOk()->assertJsonPath('can_retry', true);

        // "Intentar de nuevo": only the transaction id travels; the server finds the order through Wompi.
        $response = $this->postJson('/api/payments/wompi/attempt', ['transaction_id' => $declined['id']])->assertOk();
        $second = Payment::where('order_id', $order->id)->orderByDesc('id')->first();

        $this->assertSame($order->reference.'-2', $second->reference);
        $this->assertStringContainsString(rawurlencode($second->reference), $response->json('checkout_url'));
        $this->assertSame([1, 8, 1], [Order::count(), $this->stock(), InventoryMovement::where('order_id', $order->id)->count()]);
        // Replaying the checkout request (same idempotency key) is still the same order.
        $this->assertSame($order->id, $this->placeOrder(key: 'wompi-key-0123456789abcdef')->id);
        $this->assertSame([1, 8], [Order::count(), $this->stock()]);
    }

    public function test_a_transaction_id_of_another_store_cannot_start_an_attempt(): void
    {
        $order = $this->placeOrder();
        $this->fakeWompi(['x-1' => ['id' => 'x-1', 'status' => 'DECLINED', 'reference' => 'OTRA-TIENDA-1', 'amount_in_cents' => 1, 'currency' => 'COP']]);

        $this->postJson('/api/payments/wompi/attempt', ['transaction_id' => 'x-1'])->assertStatus(422);
        $this->postJson('/api/payments/wompi/attempt', ['transaction_id' => 'no-existe'])->assertStatus(422);
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
    }

    // ---- cancelling and the expiry command ----

    private function coupon(): Coupon
    {
        return Coupon::create(['code' => 'HELENA10', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
    }

    public function test_cancel_gives_back_stock_and_coupon_once_and_records_everything(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $this->coupon();
        $order = $this->placeOrder('HELENA10');
        $this->assertSame(8, $this->stock());
        $this->assertNull(CouponRedemption::where('order_id', $order->id)->firstOrFail()->released_at);

        $statuses = app(OrderStatusService::class);
        $statuses->cancel($order, 'Prueba de vencimiento');
        $statuses->cancel($order->fresh(), 'Otra vez'); // idempotent

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(10, $this->stock()); // back exactly once
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', 'devolucion')->count());
        $this->assertNotNull(CouponRedemption::where('order_id', $order->id)->firstOrFail()->released_at);
        $history = \App\Models\OrderStatusHistory::where('order_id', $order->id)->get();
        $this->assertSame([['pending', 'cancelled', 'Prueba de vencimiento', null]], $history->map(fn ($h) => [$h->from_status, $h->to_status, $h->note, $h->user_id])->all());
        $log = ActivityLog::where('module', 'orders')->where('action', 'status_changed')->firstOrFail();
        $this->assertSame(['status' => ['pending', 'cancelled']], $log->getAttribute('changes'));
        $this->assertSame('Sistema', $log->actor_label);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_cancel_refuses_a_paid_order(): void
    {
        $order = $this->placeOrder();
        app(PaymentService::class)->markPaid($order);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OrderStatusService::class)->cancel($order->fresh(), 'No debería');
    }

    public function test_the_expiry_command_cancels_an_unpaid_order_and_gives_back_stock_and_coupon_once(): void
    {
        $this->coupon();
        $order = $this->placeOrder('HELENA10');
        $attempt = $this->attempt($order);
        $this->fakeWompi([], [$attempt->reference => []]); // Wompi knows nothing: the customer never paid
        $this->travel(61)->minutes();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->artisan('orders:expire-unpaid')->assertSuccessful(); // a second run changes nothing

        $order->refresh();
        $this->assertSame(['cancelled', 'pending'], [$order->status, $order->payment_status]);
        $this->assertSame(10, $this->stock());
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', 'devolucion')->count());
        $this->assertNotNull(CouponRedemption::where('order_id', $order->id)->first()->released_at);
        $this->assertStringContainsString('venció', \App\Models\OrderStatusHistory::where('order_id', $order->id)->value('note'));
    }

    public function test_the_expiry_command_does_not_cancel_when_wompi_reports_an_approved_payment(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->placeOrder();
        $attempt = $this->attempt($order);
        $attempt->forceFill(['external_transaction_id' => 'tx-aprobada-1'])->save();
        $approved = $this->tx($attempt, 'APPROVED', ['id' => 'tx-aprobada-1']);
        $this->fakeWompi(['tx-aprobada-1' => $approved]);
        $this->travel(90)->minutes();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(['confirmed', 'paid'], [$order->fresh()->status, $order->fresh()->payment_status]);
        $this->assertSame(8, $this->stock()); // nothing was given back
        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    public function test_the_expiry_command_finds_a_payment_by_reference_when_the_customer_never_came_back(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->attempt($order); // no transaction id stored: the webhook and the return page never arrived
        $this->fakeWompi([], [$attempt->reference => [$this->tx($attempt)]]);
        $this->travel(61)->minutes();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_the_expiry_command_waits_for_a_pending_attempt_and_gives_up_after_24_hours(): void
    {
        $order = $this->placeOrder();
        $attempt = $this->attempt($order);
        $attempt->forceFill(['external_transaction_id' => 'tx-pse-1'])->save();
        $this->fakeWompi(['tx-pse-1' => $this->tx($attempt, 'PENDING', ['id' => 'tx-pse-1', 'payment_method_type' => 'PSE'])]);

        $this->travel(3)->hours();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status); // PSE still in flight: it is not cancelled
        $this->assertSame(8, $this->stock());

        $this->travel(22)->hours(); // 25 h after the order
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status); // the 24 h ceiling
        $this->assertSame(10, $this->stock());
    }

    public function test_the_expiry_command_never_cancels_when_wompi_cannot_be_asked_or_the_order_is_young(): void
    {
        $order = $this->placeOrder();
        $this->attempt($order);
        $this->artisan('orders:expire-unpaid')->assertSuccessful(); // 0 minutes old
        $this->assertSame('pending', $order->fresh()->status);

        $this->travel(120)->minutes();
        $this->fakeWompi(status: 500);
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(['pending', 8], [$order->fresh()->status, $this->stock()]);

        config(['services.wompi.private_key' => '']); // without keys it cannot check anything either
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_orders_of_the_manual_era_are_left_alone_unless_payment_is_required(): void
    {
        $order = $this->placeOrder(); // no attempt
        $this->fakeWompi();
        $this->travel(120)->minutes();

        config(['shop.require_payment_before_fulfillment' => false]);
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status);

        config(['shop.require_payment_before_fulfillment' => true]);
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $this->stock());
    }

    // ---- an approved payment on a cancelled order ----

    private function cancelledOrderWithLatePayment(): array
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        app(OrderStatusService::class)->cancel($order, 'Venció');
        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment)))->assertOk(); // the slow PSE finally approved

        return [$order->fresh(), $payment->fresh()];
    }

    public function test_an_approved_payment_on_a_cancelled_order_is_recorded_and_raises_a_visible_alert(): void
    {
        Event::fake([OrderPaid::class]);
        [$order, $payment] = $this->cancelledOrderWithLatePayment();

        $this->assertSame(['cancelled', 'pending', 'approved'], [$order->status, $order->payment_status, $payment->status]);
        $this->assertNotNull($payment->review_required_at);
        $this->assertNull($payment->reviewed_at);
        $this->assertSame(10, $this->stock()); // the order stays cancelled: stock is not sold again
        Event::assertNotDispatched(OrderPaid::class);
        $row = ActivityLog::where('module', 'orders')->where('action', 'payment_received_on_cancelled')->firstOrFail();
        $this->assertSame('Sistema', $row->actor_label);

        $admin = $this->person('admin');
        $alerts = $this->actingAs($admin)->getJson('/admin/today/data')->assertOk()->json('payment_alerts');
        $this->assertSame([[$order->reference, 100000]], collect($alerts)->map(fn ($a) => [$a['reference'], $a['amount_cop']])->all());
        $this->assertSame(1, count($this->getJson('/admin/dashboard/summary')->json('payment_alerts')));
        $list = $this->getJson('/admin/orders/data')->json('data');
        $this->assertTrue($list[0]['payment_alert']);
        $this->assertTrue($this->getJson("/admin/orders/{$order->id}/detail")->json('order.payment_alert'));
        $this->assertNotNull($this->getJson("/admin/orders/{$order->id}/detail")->json('order.payments.0.review_required_at'));
    }

    public function test_only_an_administrator_sees_and_clears_the_alert_and_the_review_is_recorded(): void
    {
        [$order] = $this->cancelledOrderWithLatePayment();
        $admin = $this->person('admin');
        $dispatch = $this->person('dispatch');

        $this->actingAs($dispatch);
        $this->assertArrayNotHasKey('payment_alerts', $this->getJson('/admin/today/data')->json());
        $this->postJson("/admin/orders/{$order->id}/wompi/review")->assertForbidden();
        $this->postJson("/admin/orders/{$order->id}/wompi/refresh")->assertForbidden();

        $this->actingAs($admin)->postJson("/admin/orders/{$order->id}/wompi/review")->assertOk()->assertJsonPath('reviewed', 1);
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame([$admin->id], [$payment->reviewed_by]);
        $this->assertNotNull($payment->reviewed_at);
        $this->assertSame([], $this->getJson('/admin/today/data')->json('payment_alerts'));
        $this->assertFalse($this->getJson('/admin/orders/data')->json('data.0.payment_alert'));
        $row = ActivityLog::where('action', 'payment_reviewed')->firstOrFail();
        $this->assertSame($admin->id, $row->user_id);
        $this->postJson("/admin/orders/{$order->id}/wompi/review")->assertOk()->assertJsonPath('reviewed', 0); // nothing left to review
    }

    // ---- admin ----

    public function test_the_admin_refresh_asks_wompi_and_cannot_mark_anything_paid_by_hand(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $payment->forceFill(['external_transaction_id' => 'tx-admin-1'])->save();
        $admin = $this->person('admin');
        $this->actingAs($admin);

        // Wompi says PENDING: whatever the request body claims, the order does not move.
        $this->fakeWompi(['tx-admin-1' => $this->tx($payment, 'PENDING', ['id' => 'tx-admin-1'])]);
        $this->postJson("/admin/orders/{$order->id}/wompi/refresh", ['payment_status' => 'paid', 'status' => 'confirmed'])->assertOk();
        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $order->fresh()->payment_status]);

        // Wompi says APPROVED: the same rules as the webhook apply.
        $this->fakeWompi(['tx-admin-1' => $this->tx($payment, 'APPROVED', ['id' => 'tx-admin-1', 'payment_method_type' => 'PSE'])]);
        $this->postJson("/admin/orders/{$order->id}/wompi/refresh")->assertOk()->assertJsonPath('outcomes.'.$payment->reference, 'applied');
        $this->assertSame(['confirmed', 'paid'], [$order->fresh()->status, $order->fresh()->payment_status]);

        $this->fakeWompi(status: 500);
        $this->postJson("/admin/orders/{$order->id}/wompi/refresh")->assertStatus(503);
        config(['services.wompi.events_secret' => '']);
        $this->postJson("/admin/orders/{$order->id}/wompi/refresh")->assertStatus(422);
    }

    public function test_no_admin_endpoint_can_mark_an_order_as_paid_or_confirmed(): void
    {
        $order = $this->placeOrder();
        $this->attempt($order);
        $admin = $this->person('admin');
        $this->fakeWompi(); // Wompi knows nothing

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin') || ! str_contains($route->uri(), '{order}') || in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = '/'.str_replace('{order}', (string) $order->id, $route->uri());
            foreach ([[], ['status' => 'confirmed', 'payment_status' => 'paid', 'paid_at' => now()->toDateTimeString(), 'reviewed' => true]] as $payload) {
                $this->actingAs($admin)->json($route->methods()[0], $uri, $payload);
                $fresh = $order->fresh();
                $this->assertNotSame('confirmed', $fresh->status, "{$route->uri()} confirmed the order");
                $this->assertSame('pending', $fresh->payment_status, "{$route->uri()} marked the order as paid");
                $this->assertNull($fresh->paid_at);
            }
            $order->forceFill(['status' => 'pending', 'payment_status' => 'pending'])->save();
        }
    }

    public function test_the_admin_payment_section_shows_each_attempt_with_its_method(): void
    {
        $order = $this->placeOrder();
        $first = $this->attempt($order);
        app(PaymentService::class)->applyTransaction($first, $this->tx($first, 'DECLINED', ['payment_method_type' => 'NEQUI']));
        $this->travel(31)->minutes();
        $second = $this->attempt($order);
        app(PaymentService::class)->applyTransaction($second, $this->tx($second, 'APPROVED', ['payment_method_type' => 'CARD']));

        $payments = $this->actingAs($this->person('admin'))->getJson("/admin/orders/{$order->id}/detail")->assertOk()->json('order.payments');

        $this->assertSame([['declined', 'NEQUI', 'Fondos insuficientes'], ['approved', 'CARD', null]], collect($payments)->map(fn ($p) => [$p['status'], $p['payment_method_type'], $p['status_message']])->all());
        $this->assertNotNull($payments[1]['confirmed_at']);
        $this->assertNotNull($payments[0]['created_at']);
    }

    // ---- secrets and checkout rules ----

    public function test_no_secret_reaches_the_historial_a_response_or_the_page(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);
        $this->postJson('/api/payments/wompi/events', $this->event($this->tx($payment)))->assertOk();
        $admin = $this->person('admin');

        $everything = [
            $this->get('/')->getContent(),
            $this->actingAs($admin)->getJson("/admin/orders/{$order->id}/detail")->getContent(),
            $this->getJson('/admin/today/data')->getContent(),
            $this->getJson('/admin/session')->getContent(),
            json_encode(ActivityLog::all()->toArray()),
            json_encode(PaymentEvent::all()->toArray()),
            json_encode(Payment::all()->toArray()),
        ];
        foreach (['integrity_secret', 'events_secret', 'private_key'] as $secret) {
            foreach ($everything as $text) {
                $this->assertStringNotContainsString(self::KEYS[$secret], $text, "$secret leaked");
            }
        }
    }

    public function test_the_order_still_registers_as_pending_without_a_payment_and_prices_come_from_the_database(): void
    {
        $order = $this->placeOrder();

        $this->assertSame(['pending', 'pending', 100000], [$order->status, $order->payment_status, $order->total_cop]);
        $this->assertSame(0, Payment::count()); // the attempt is a separate, explicit step
        $this->assertSame(8, $this->stock());
    }
}
