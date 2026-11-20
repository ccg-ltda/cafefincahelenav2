<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PaymentService;
use App\Services\WompiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** A customer comes back to the store with a pending order: its state, "Pagar ahora" (same order) and "Cancelar pedido". Wompi is simulated. */
class PendingOrderTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'env' => 'sandbox', 'public_key' => 'pub_test_FAKEPUBLICKEY0000000000000000', 'private_key' => 'prv_test_FAKEPRIVATEKEY000000000000000',
        'integrity_secret' => 'test_integrity_FAKEINTEGRITY000000000000', 'events_secret' => 'test_events_FAKEEVENTS000000000000000',
    ];

    private ProductVariant $variant;

    /** @var array<string, array<string, mixed>> Wompi transactions by id */
    private array $wompiById = [];

    private bool $wompiDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wompi' => self::KEYS + ['checkout_window_minutes' => 30, 'pending_expiry_minutes' => 60], 'app.url' => 'https://tienda.example.com']);
        foreach (['payment-attempt', 'pending-order-status', 'pending-order-cancel', 'order-store'] as $limiter) {
            RateLimiter::clear($limiter);
        }
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Café Premium', 'slug' => 'cafe-premium', 'is_published' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g', 'price_cop' => 50000, 'is_published' => true]);
        $this->variant->forceFill(['stock_quantity' => 10])->save();
        Coupon::create(['code' => 'HELENA10', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        Http::fake(function ($request) {
            if ($this->wompiDown) {
                return Http::response([], 503);
            }
            if (preg_match('#/v1/transactions/([^/?]+)$#', $request->url(), $found)) {
                $id = urldecode($found[1]);

                return isset($this->wompiById[$id]) ? Http::response(['data' => $this->wompiById[$id]]) : Http::response(['error' => 'not found'], 404);
            }

            return Http::response(['data' => []]); // by reference: nothing there
        });
    }

    private function placeOrder(?string $coupon = 'HELENA10', string $key = 'pending-key-0123456789abcdef', string $email = 'maria.gonzalez@example.com'): Order
    {
        $response = $this->postJson('/api/orders', [
            'idempotency_key' => $key, 'customer_name' => 'María González', 'customer_email' => $email, 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'terms_accepted' => true, 'data_consent' => true,
            'coupon_code' => $coupon, 'items' => [['variant_id' => $this->variant->id, 'quantity' => 2]],
        ])->assertSuccessful();

        return Order::where('reference', $response->json('order.reference'))->firstOrFail();
    }

    private function stock(): int
    {
        return (int) $this->variant->fresh()->stock_quantity;
    }

    private function body(Order $order, ?string $email = null): array
    {
        return ['reference' => $order->reference, 'customer_email' => $email ?? $order->customer_email];
    }

    /** An attempt of the order with a transaction Wompi knows, in the given status. */
    private function attemptWithTransaction(Order $order, string $status): Payment
    {
        $payment = app(PaymentService::class)->startAttempt($order);
        $payment->forceFill(['external_transaction_id' => 'tx-'.$payment->id])->save();
        $this->wompiById['tx-'.$payment->id] = ['id' => 'tx-'.$payment->id, 'status' => $status, 'reference' => $payment->reference, 'amount_in_cents' => $payment->amount_cop * 100, 'currency' => 'COP', 'payment_method_type' => 'PSE'];

        return $payment;
    }

    // ---- the state ----

    public function test_a_pending_order_answers_pending_with_its_reference_total_and_can_cancel(): void
    {
        $order = $this->placeOrder();

        $this->postJson('/api/orders/pending/status', $this->body($order))->assertOk()
            ->assertExactJson(['state' => 'pending', 'reference' => $order->reference, 'total_cop' => $order->total_cop, 'can_cancel' => true]);
    }

    public function test_the_email_is_compared_without_case_or_spaces(): void
    {
        $order = $this->placeOrder();

        $this->postJson('/api/orders/pending/status', $this->body($order, '  MARIA.Gonzalez@Example.com '))->assertOk()->assertJsonPath('state', 'pending');
    }

    public function test_a_paid_order_answers_paid_and_a_cancelled_one_answers_cancelled(): void
    {
        $paid = $this->placeOrder(null, 'pending-key-paid-0123456789');
        app(PaymentService::class)->markPaid($paid);
        $this->postJson('/api/orders/pending/status', $this->body($paid))->assertOk()->assertJsonPath('state', 'paid')->assertJsonPath('can_cancel', false);

        $cancelled = $this->placeOrder(null, 'pending-key-canc-0123456789');
        $this->postJson('/api/orders/pending/cancel', $this->body($cancelled))->assertOk();
        $this->postJson('/api/orders/pending/status', $this->body($cancelled))->assertOk()->assertJsonPath('state', 'cancelled');
    }

    public function test_a_payment_approved_in_wompi_is_seen_as_paid_even_if_the_webhook_has_not_arrived(): void
    {
        $order = $this->placeOrder();
        $this->attemptWithTransaction($order, 'APPROVED');

        $this->postJson('/api/orders/pending/status', $this->body($order))->assertOk()->assertJsonPath('state', 'paid');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_payment_still_pending_in_wompi_cannot_be_cancelled(): void
    {
        $order = $this->placeOrder();
        $this->attemptWithTransaction($order, 'PENDING');

        $this->postJson('/api/orders/pending/status', $this->body($order))->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('can_cancel', false);
    }

    // ---- who may ask: generic answers ----

    public function test_a_wrong_email_a_wrong_reference_and_a_missing_order_get_the_same_answer(): void
    {
        $order = $this->placeOrder();
        $cases = [
            $this->body($order, 'otra.persona@example.com'),
            ['reference' => 'CFH-ZZZZZZZZZZZZ', 'customer_email' => $order->customer_email],
            ['reference' => 'no-es-una-referencia', 'customer_email' => $order->customer_email],
        ];
        $answers = [];
        foreach ($cases as $body) {
            $answers[] = $this->postJson('/api/orders/pending/status', $body)->assertOk()->getContent();
        }
        $this->assertCount(1, array_unique($answers));
        $this->assertSame('{"state":"unknown"}', $answers[0]);
        $this->assertStringNotContainsString($order->reference, $answers[0]);

        // Incomplete or wrongly typed input (an array used to be a 500) is a plain 422 on both doors, and says nothing about any order.
        foreach (['status', 'cancel'] as $door) {
            foreach ([['reference' => $order->reference], [], ['reference' => [$order->reference], 'customer_email' => $order->customer_email], ['reference' => $order->reference, 'customer_email' => ['x']]] as $body) {
                $this->postJson("/api/orders/pending/{$door}", $body)->assertStatus(422);
            }
        }
    }

    public function test_the_order_of_another_customer_is_never_cancelled(): void
    {
        $order = $this->placeOrder();
        $before = $this->stock();

        $wrongEmail = $this->postJson('/api/orders/pending/cancel', $this->body($order, 'otra.persona@example.com'));
        $wrongReference = $this->postJson('/api/orders/pending/cancel', ['reference' => 'CFH-ZZZZZZZZZZZZ', 'customer_email' => $order->customer_email]);

        $wrongEmail->assertNotFound();
        $this->assertSame($wrongEmail->getContent(), $wrongReference->getContent()); // not even the answer says if the order exists
        $this->assertSame(['pending', $before], [$order->fresh()->status, $this->stock()]);
        $this->assertSame(0, ActivityLog::where('action', 'status_changed')->count());
    }

    public function test_both_doors_have_their_own_rate_limit(): void
    {
        $order = $this->placeOrder();
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/orders/pending/status', $this->body($order, 'x@example.com'))->assertOk();
        }
        $this->postJson('/api/orders/pending/status', $this->body($order, 'x@example.com'))->assertStatus(429);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/orders/pending/cancel', $this->body($order, 'x@example.com'))->assertNotFound();
        }
        $this->postJson('/api/orders/pending/cancel', $this->body($order, 'x@example.com'))->assertStatus(429);
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])->assertSuccessful(); // the other buckets are untouched
    }

    // ---- cancelling ----

    public function test_cancelling_gives_back_stock_and_coupon_once_and_records_the_customer_in_the_historial(): void
    {
        Mail::fake();
        $order = $this->placeOrder();
        $this->assertSame(8, $this->stock());
        $this->assertNull(CouponRedemption::where('order_id', $order->id)->firstOrFail()->released_at);

        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertOk()->assertExactJson(['state' => 'cancelled']);
        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertOk()->assertExactJson(['state' => 'cancelled']); // double click / second tab

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $this->stock());
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', 'devolucion')->count());
        $this->assertNotNull(CouponRedemption::where('order_id', $order->id)->firstOrFail()->released_at);
        $log = ActivityLog::where('module', 'orders')->where('action', 'status_changed')->get();
        $this->assertCount(1, $log);
        $this->assertSame('Cliente', $log->first()->actor_label);
        $this->assertSame(1, $order->history()->where('to_status', 'cancelled')->count());
    }

    public function test_cancelling_an_unpaid_order_sends_no_email_to_anyone(): void
    {
        Mail::fake();
        config(['shop.admin_notification_emails' => ['dueno@tienda.example.com']]);
        $order = $this->placeOrder();

        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(0, OrderNotification::count());
    }

    public function test_it_is_not_cancelled_while_wompi_has_a_payment_in_process(): void
    {
        $order = $this->placeOrder();
        $this->attemptWithTransaction($order, 'PENDING');

        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertStatus(409)->assertJsonPath('state', 'pending');

        $this->assertSame(['pending', 8], [$order->fresh()->status, $this->stock()]);
    }

    public function test_it_is_not_cancelled_when_wompi_says_it_was_approved_and_the_order_becomes_paid(): void
    {
        $order = $this->placeOrder();
        $this->attemptWithTransaction($order, 'APPROVED');

        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertStatus(409)->assertJsonPath('state', 'paid');

        $fresh = $order->fresh();
        $this->assertSame(['paid', 8], [$fresh->payment_status, $this->stock()]);
        $this->assertNotSame('cancelled', $fresh->status);
    }

    public function test_it_is_never_cancelled_on_a_guess_when_wompi_cannot_be_asked(): void
    {
        $order = $this->placeOrder();
        app(PaymentService::class)->startAttempt($order);
        $this->wompiDown = true;

        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertStatus(503);

        $this->assertSame(['pending', 8], [$order->fresh()->status, $this->stock()]);
    }

    public function test_a_paid_order_cannot_be_cancelled_by_the_customer(): void
    {
        $order = $this->placeOrder();
        app(PaymentService::class)->markPaid($order);

        $this->postJson('/api/orders/pending/cancel', $this->body($order))->assertStatus(409)->assertJsonPath('state', 'paid');

        $this->assertNotSame('cancelled', $order->fresh()->status);
        $this->assertSame(8, $this->stock());
    }

    // ---- "Pagar ahora": the same order ----

    public function test_pay_now_creates_a_new_attempt_on_the_same_order_and_never_a_second_order(): void
    {
        $order = $this->placeOrder();
        $this->attemptWithTransaction($order, 'DECLINED'); // the first payment failed

        $reply = $this->postJson('/api/payments/wompi/attempt', $this->body($order))->assertOk();

        $this->assertStringContainsString('checkout.wompi.co', $reply->json('checkout_url'));
        $this->assertSame(1, Order::count());
        $this->assertSame(2, Payment::where('order_id', $order->id)->count());
        $this->assertSame($order->reference.'-2', Payment::where('order_id', $order->id)->orderByDesc('id')->value('reference'));
        $this->assertSame(8, $this->stock()); // nothing taken twice
        $this->assertTrue(app(WompiService::class)->enabled());
    }
}
