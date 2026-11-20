<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Events\PaymentReceivedOnCancelledOrder;
use App\Mail\OrderNotificationMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNotification;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderNotificationService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Support\OrderEmailData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Transactional emails: who gets what, once only, never blocking the order, and the admin side (section, resend, preview). */
class OrderEmailTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://tienda.example.com', 'shop.whatsapp_number' => '573027880823', 'shop.admin_notification_emails' => ['dueno@tienda.example.com', 'gerente@tienda.example.com']]);
    }

    private function person(string $role): User
    {
        $n = ++self::$n;

        return User::factory()->create(['role' => $role, 'name' => "Persona $role $n", 'username' => "$role.correo$n", 'email' => "$role.correo$n@example.com"]);
    }

    private function order(string $status = 'confirmed', array $override = []): Order
    {
        $n = ++self::$n;
        $order = Order::create($override + [
            'reference' => sprintf('CFH-%012d', $n), 'customer_name' => 'María González', 'customer_email' => 'maria.gonzalez@example.com', 'customer_phone' => '3001234567',
            'delivery_address' => 'Calle 45 # 12-34', 'delivery_details' => 'Apto 301', 'order_notes' => 'Dejar con el portero', 'delivery_city' => 'Bogotá', 'delivery_department' => 'Cundinamarca',
            'subtotal_cop' => 72000, 'shipping_cop' => 0, 'discount_cop' => 7200, 'coupon_code' => 'HELENA10', 'total_cop' => 64800, 'currency' => 'COP', 'status' => $status, 'payment_status' => 'paid',
            'paid_at' => now(), 'terms_accepted_at' => now(), 'data_consent_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g · Molido', 'quantity' => 2, 'unit_price_cop' => 36000, 'original_unit_price_cop' => 40000, 'subtotal_cop' => 72000]);

        return $order;
    }

    private function sentTo(string $email, ?string $type = null): int
    {
        return Mail::sent(OrderNotificationMail::class, fn (OrderNotificationMail $mail) => $mail->hasTo($email) && ($type === null || $mail->type === $type))->count();
    }

    // ---- Who gets what ----

    public function test_a_paid_order_sends_the_receipt_to_the_customer_and_one_notice_to_each_administrator(): void
    {
        Mail::fake();
        $order = $this->order();

        OrderPaid::dispatch($order);

        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_paid'));
        $this->assertSame(1, $this->sentTo('dueno@tienda.example.com', 'admin_new_paid'));
        $this->assertSame(1, $this->sentTo('gerente@tienda.example.com', 'admin_new_paid'));
        $this->assertSame(3, OrderNotification::where('order_id', $order->id)->where('status', 'sent')->count());
        Mail::assertSentCount(3);
    }

    public function test_the_same_payment_event_twice_sends_each_email_once(): void
    {
        Mail::fake();
        $order = $this->order();

        OrderPaid::dispatch($order);
        OrderPaid::dispatch($order);
        OrderPaid::dispatch($order->fresh());

        Mail::assertSentCount(3);
        $this->assertSame(3, OrderNotification::count());
    }

    public function test_a_repeated_wompi_confirmation_does_not_repeat_the_emails(): void
    {
        Mail::fake();
        $order = $this->order('pending', ['payment_status' => 'pending', 'paid_at' => null]);
        $payments = app(PaymentService::class);

        $payments->markPaid($order);
        $payments->markPaid($order->fresh()); // webhook delivered twice

        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_paid'));
        Mail::assertSentCount(3);
    }

    public function test_each_status_the_customer_follows_sends_one_email_and_the_others_none(): void
    {
        Mail::fake();
        $order = $this->order('confirmed');

        foreach (['pending', 'confirmed'] as $silent) {
            OrderStatusChanged::dispatch($order, 'x', $silent);
        }
        Mail::assertNothingSent();

        foreach (['preparing', 'ready', 'shipped', 'delivered'] as $to) {
            OrderStatusChanged::dispatch($order, 'x', $to);
            $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', "customer_$to"));
        }
        Mail::assertSentCount(4);
        $this->assertSame(0, $this->sentTo('dueno@tienda.example.com')); // statuses never reach the administrators
    }

    public function test_status_emails_go_out_even_when_the_order_is_not_paid_yet(): void
    {
        Mail::fake();
        $order = $this->order('preparing', ['payment_status' => 'pending', 'paid_at' => null]);

        OrderStatusChanged::dispatch($order, 'pending', 'preparing');

        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_preparing'));
    }

    public function test_cancelling_tells_the_customer_only_when_the_order_was_paid_and_mentions_the_refund_without_dates(): void
    {
        Mail::fake();
        $unpaid = $this->order('cancelled', ['payment_status' => 'pending', 'paid_at' => null]);
        OrderStatusChanged::dispatch($unpaid, 'pending', 'cancelled');
        Mail::assertNothingSent();

        $paid = $this->order('cancelled');
        OrderStatusChanged::dispatch($paid, 'preparing', 'cancelled');
        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_cancelled'));

        $html = (new OrderNotificationMail($paid->load('items'), 'customer_cancelled'))->render();
        $this->assertStringContainsString('te contactaremos para coordinar la devolución de tu dinero', $html);
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(días|horas)\b/u', strip_tags($html));
    }

    public function test_an_unpaid_order_that_expires_sends_nothing(): void
    {
        Mail::fake();
        $order = $this->order('pending', ['payment_status' => 'pending', 'paid_at' => null]);

        app(OrderStatusService::class)->cancel($order, 'Venció sin pago');

        $this->assertSame('cancelled', $order->fresh()->status);
        Mail::assertNothingSent();
        $this->assertSame(0, OrderNotification::count());
    }

    public function test_a_payment_on_a_cancelled_order_alerts_the_administrators(): void
    {
        Mail::fake();
        $order = $this->order('cancelled');

        PaymentReceivedOnCancelledOrder::dispatch($order, new Payment);

        $this->assertSame(1, $this->sentTo('dueno@tienda.example.com', 'admin_payment_on_cancelled'));
        $this->assertSame(1, $this->sentTo('gerente@tienda.example.com', 'admin_payment_on_cancelled'));
        $this->assertSame(0, $this->sentTo('maria.gonzalez@example.com'));
    }

    public function test_without_administrator_recipients_only_the_customer_is_mailed_and_a_warning_is_logged(): void
    {
        Mail::fake();
        config(['shop.admin_notification_emails' => []]);
        Log::spy();
        $order = $this->order();

        OrderPaid::dispatch($order);

        Mail::assertSentCount(1);
        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_paid'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'SHOP_ADMIN_NOTIFICATION_EMAILS'))->atLeast()->once();
    }

    public function test_the_admin_list_comes_from_the_env_and_drops_invalid_addresses(): void
    {
        // The developer's own .env may already define the variable: override every place env() reads from, then restore them.
        $key = 'SHOP_ADMIN_NOTIFICATION_EMAILS';
        $before = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        $value = ' Uno@Tienda.com , malo, dos@tienda.com,uno@tienda.com ';
        putenv("$key=$value");
        $_ENV[$key] = $_SERVER[$key] = $value;
        try {
            $config = require config_path('shop.php');
        } finally {
            $before[0] === false ? putenv($key) : putenv("$key={$before[0]}");
            $before[1] === null ? $_ENV[$key] = null : $_ENV[$key] = $before[1];
            $before[2] === null ? $_SERVER[$key] = null : $_SERVER[$key] = $before[2];
            if ($before[1] === null) { unset($_ENV[$key]); }
            if ($before[2] === null) { unset($_SERVER[$key]); }
        }

        $this->assertSame(['uno@tienda.com', 'dos@tienda.com'], $config['admin_notification_emails']);
    }

    // ---- Never blocks the order, retries ----

    public function test_a_failing_email_never_undoes_the_payment_and_is_stored_without_the_full_address(): void
    {
        $order = $this->order('pending', ['payment_status' => 'pending', 'paid_at' => null]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('550 mailbox unavailable for maria.gonzalez@example.com'));

        app(PaymentService::class)->markPaid($order); // must not throw

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame('confirmed', $fresh->status);
        $row = OrderNotification::where('order_id', $order->id)->where('type', 'customer_paid')->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertGreaterThanOrEqual(1, $row->attempts);
        $this->assertStringContainsString('m***@example.com', $row->error);
        $this->assertStringNotContainsString('maria.gonzalez@example.com', $row->error);
    }

    public function test_a_failed_email_can_be_retried_and_then_goes_out_once(): void
    {
        $order = $this->order();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        OrderPaid::dispatch($order);
        $row = OrderNotification::where('type', 'customer_paid')->firstOrFail();
        $this->assertNotSame('sent', $row->status);

        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();
        app(OrderNotificationService::class)->deliver($row->id); // what the queue does on the next attempt

        $this->assertSame('sent', $row->fresh()->status);
        $this->assertNull($row->fresh()->error);
        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_paid'));
        app(OrderNotificationService::class)->deliver($row->id); // already sent: nothing again
        $this->assertSame(1, $this->sentTo('maria.gonzalez@example.com', 'customer_paid'));
    }

    public function test_the_job_keeps_its_own_tries_and_backoff_over_the_worker_option(): void
    {
        $job = new \App\Jobs\SendOrderNotification(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'queue:work'));
        $this->assertNotNull($scheduled);
        $this->assertSame('* * * * *', $scheduled->expression);
        $this->assertStringContainsString('--stop-when-empty', $scheduled->command);
    }

    // ---- Content ----

    public function test_the_receipt_has_the_brand_the_card_the_table_the_totals_and_the_legal_line(): void
    {
        $order = $this->order()->load(['items.variant', 'assignedDelivery']);
        $mail = new OrderNotificationMail($order, 'customer_paid');
        $html = $mail->render();

        $this->assertSame("Recibimos tu pago · Pedido {$order->reference}", $mail->envelope()->subject);
        foreach (['https://tienda.example.com/media/email/logo.png', 'Café Finca Helena', 'Café de Norte de Santander', '¡Gracias, María!', $order->reference, 'Pagado', '$64.800',
            'Café Premium 250 g · Molido', 'line-through', '$40.000', 'Descuento por promociones', 'HELENA10', 'A coordinar', 'Calle 45 # 12-34', 'Cundinamarca', 'https://wa.me/573027880823',
            'Este correo es un comprobante de compra; no es una factura electrónica.', 'prefers-color-scheme: dark', 'width="600"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('/admin/', $html); // the customer never gets the panel link
    }

    public function test_the_shipped_email_lists_the_carrier_and_the_tracking_number_and_the_courier(): void
    {
        $courier = $this->person('delivery');
        $order = $this->order('shipped', ['carrier' => 'Servientrega', 'tracking_number' => 'GUIA-998877', 'assigned_delivery_user_id' => $courier->id])->load(['items.variant', 'assignedDelivery']);

        $html = (new OrderNotificationMail($order, 'customer_shipped'))->render();

        $this->assertStringContainsString('Servientrega', $html);
        $this->assertStringContainsString('GUIA-998877', $html);
        $this->assertStringContainsString($courier->name, $html);
        $this->assertStringNotContainsString('comprobante de compra', $html); // only the receipt says it
    }

    public function test_the_admin_email_has_the_contact_the_address_the_notes_and_the_panel_button(): void
    {
        $order = $this->order()->load(['items.variant', 'assignedDelivery']);
        $mail = new OrderNotificationMail($order, 'admin_new_paid');
        $html = $mail->render();

        $this->assertSame("Nuevo pedido {$order->reference} · María González · \$64.800", $mail->envelope()->subject);
        foreach (['maria.gonzalez@example.com', '3001234567', 'Calle 45 # 12-34', 'Apto 301', 'Bogotá', 'Cundinamarca', 'Dejar con el portero', 'HELENA10', 'Ver pedido en el panel', "https://tienda.example.com/admin/orders/{$order->id}"] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    public function test_every_type_renders_in_html_and_plain_text(): void
    {
        $order = $this->order()->load(['items.variant', 'assignedDelivery']);
        foreach (array_keys(OrderEmailData::TYPES) as $type) {
            $data = OrderEmailData::for($order, $type);
            $this->assertStringContainsString($order->reference, (new OrderNotificationMail($order, $type))->render(), $type);
            $text = view('emails.order-text', ['d' => $data])->render();
            $this->assertStringContainsString($order->reference, $text, $type);
            $this->assertStringNotContainsString('<', $text, $type);
        }
    }

    // ---- Admin: section, resend, preview ----

    public function test_only_the_admin_sees_the_emails_of_an_order_with_the_address_masked(): void
    {
        Mail::fake();
        $order = $this->order();
        OrderPaid::dispatch($order);

        $this->actingAs($this->person('admin'))->getJson("/admin/orders/{$order->id}/detail")->assertOk()
            ->assertJsonCount(3, 'order.notifications')->assertJsonPath('order.notifications.0.recipient', 'm***@example.com')->assertJsonPath('order.notifications.0.status', 'sent');
        $this->actingAs($this->person('dispatch'))->getJson("/admin/orders/{$order->id}/detail")->assertOk()->assertJsonMissingPath('order.notifications');
        $this->assertStringNotContainsString('maria.gonzalez@example.com', json_encode($this->actingAs($this->person('admin'))->getJson("/admin/orders/{$order->id}/detail")->json('order.notifications')));
    }

    public function test_the_admin_can_resend_an_email_and_it_is_recorded_in_the_historial(): void
    {
        Mail::fake();
        $order = $this->order();
        OrderPaid::dispatch($order);
        $row = OrderNotification::where('type', 'customer_paid')->firstOrFail();
        $admin = $this->person('admin');

        $this->actingAs($admin)->postJson("/admin/orders/{$order->id}/notifications/{$row->id}/resend")->assertOk()->assertJsonCount(3, 'notifications');

        $this->assertSame(2, $this->sentTo('maria.gonzalez@example.com', 'customer_paid'));
        $this->assertSame('sent', $row->fresh()->status);
        $log = ActivityLog::where('module', 'orders')->where('action', 'email_resent')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringNotContainsString('maria.gonzalez@example.com', json_encode($log->context));
    }

    public function test_resending_is_refused_while_the_email_is_still_queued_and_for_other_orders_or_roles(): void
    {
        Mail::fake();
        $order = $this->order();
        $other = $this->order();
        OrderPaid::dispatch($order);
        $row = OrderNotification::where('order_id', $order->id)->where('type', 'customer_paid')->firstOrFail();

        $row->forceFill(['status' => 'queued'])->save();
        $this->actingAs($this->person('admin'))->postJson("/admin/orders/{$order->id}/notifications/{$row->id}/resend")->assertStatus(422);
        $this->actingAs($this->person('admin'))->postJson("/admin/orders/{$other->id}/notifications/{$row->id}/resend")->assertNotFound();

        $row->forceFill(['status' => 'sent'])->save();
        foreach (['dispatch', 'delivery'] as $role) {
            $this->actingAs($this->person($role))->postJson("/admin/orders/{$order->id}/notifications/{$row->id}/resend")->assertForbidden();
        }
        $this->assertSame(0, ActivityLog::where('action', 'email_resent')->count());
    }

    public function test_the_preview_shows_every_template_with_sample_or_real_data_and_sends_nothing(): void
    {
        Mail::fake();
        $order = $this->order();
        $this->actingAs($this->person('admin'));

        $this->get('/admin/emails/preview')->assertOk()->assertSee('Vista previa de correos')->assertSee($order->reference);
        foreach (array_keys(OrderEmailData::TYPES) as $type) {
            $this->get("/admin/emails/preview/$type")->assertOk()->assertSee('CFH-EJEMPLO00001')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->get("/admin/emails/preview/$type?order={$order->id}")->assertOk()->assertSee($order->reference);
            $this->get("/admin/emails/preview/$type?order={$order->id}&format=text")->assertOk()->assertSee($order->reference);
        }
        $this->get('/admin/emails/preview/inventado')->assertNotFound();
        $this->get('/admin/emails/preview/customer_paid?order=999999')->assertNotFound();
        Mail::assertNothingSent();
        $this->assertSame(0, OrderNotification::count());
    }

    public function test_the_preview_is_closed_to_dispatch_delivery_and_visitors(): void
    {
        foreach (['dispatch', 'delivery'] as $role) {
            $this->actingAs($this->person($role))->get('/admin/emails/preview')->assertForbidden();
            $this->get('/admin/emails/preview/customer_paid')->assertForbidden();
        }
        auth()->logout();
        $this->get('/admin/emails/preview')->assertRedirect('/admin/login');
    }

    public function test_dispatch_and_delivery_people_never_get_emails(): void
    {
        Mail::fake();
        $this->person('dispatch');
        $this->person('delivery');
        $order = $this->order();

        OrderPaid::dispatch($order);
        OrderStatusChanged::dispatch($order, 'confirmed', 'preparing');
        OrderStatusChanged::dispatch($order, 'preparing', 'shipped');

        Mail::assertSent(OrderNotificationMail::class, fn ($mail) => ! $mail->hasTo('dispatch.correo1@example.com'));
        $this->assertSame(0, OrderNotification::whereIn('recipient', User::whereIn('role', ['dispatch', 'delivery'])->pluck('email'))->count());
    }
}
