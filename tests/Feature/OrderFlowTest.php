<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The order flow (pending -> confirmed -> preparing -> ready -> shipped -> delivered) and Hoy. */
class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function person(string $role, array $attributes = []): User
    {
        $n = ++self::$n;

        return User::factory()->create($attributes + ['role' => $role, 'name' => "Persona $role $n", 'username' => "$role.flujo$n", 'email' => "$role.flujo$n@example.com"]);
    }

    private function order(string $status = 'confirmed', array $override = []): Order
    {
        $n = ++self::$n;
        $order = Order::create($override + [
            'reference' => sprintf('CFH-%012d', $n), 'customer_name' => "Cliente $n", 'customer_email' => "secreto$n@cliente.com", 'customer_phone' => '3001234567',
            'delivery_address' => 'Calle 45 # 12-34', 'delivery_details' => 'Apto 301', 'order_notes' => 'Dejar con el portero', 'delivery_city' => 'Cúcuta', 'delivery_department' => 'Norte de Santander',
            'subtotal_cop' => 73450, 'shipping_cop' => 0, 'discount_cop' => 3450, 'coupon_code' => 'SECRETO10', 'total_cop' => 70000, 'currency' => 'COP', 'status' => $status, 'payment_status' => 'pending',
            'terms_accepted_at' => now(), 'data_consent_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'FH-001', 'name' => 'Kit Degustación 2×125 g · Molido', 'quantity' => 2, 'unit_price_cop' => 36725, 'subtotal_cop' => 73450]);

        return $order;
    }

    // ---- The states ----

    public function test_the_labels_come_from_one_list_and_reach_the_frontend_through_the_session(): void
    {
        $expected = ['pending' => 'Pendiente de pago', 'confirmed' => 'Pagado', 'preparing' => 'En preparación', 'ready' => 'Preparado', 'shipped' => 'En camino', 'delivered' => 'Entregado', 'cancelled' => 'Cancelado'];
        $this->assertSame($expected, OrderStatusService::LABELS);
        foreach (['admin', 'dispatch', 'delivery'] as $role) {
            $this->actingAs($this->person($role))->getJson('/admin/session')->assertOk()->assertJsonPath('order_statuses', $expected);
        }
    }

    public function test_every_valid_transition_works_and_stamps_the_stage_time(): void
    {
        $dispatch = $this->person('dispatch');
        $courier = $this->person('delivery');
        $order = $this->order('confirmed', ['status_changed_at' => now()->subHours(3)]);
        $this->actingAs($dispatch);

        $this->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => $order->fresh()->status])->assertOk()->assertJsonPath('order.status', 'preparing');
        $this->assertTrue($order->fresh()->status_changed_at->greaterThan(now()->subMinute()));
        $this->patchJson("/admin/orders/{$order->id}/ready", ['expected_status' => $order->fresh()->status])->assertOk()->assertJsonPath('order.status', 'ready');
        $this->patchJson("/admin/orders/{$order->id}/ship", ['expected_status' => $order->fresh()->status, 'delivery_user_id' => $courier->id])->assertOk()->assertJsonPath('order.status', 'shipped');
        $this->patchJson("/admin/orders/{$order->id}/deliver", ['expected_status' => $order->fresh()->status])->assertOk()->assertJsonPath('order.status', 'delivered');
        $this->assertSame(4, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_invalid_transitions_are_refused_in_every_direction(): void
    {
        $this->actingAs($this->person('admin'));
        $cases = [ // status => steps that must be refused
            'confirmed' => ['ready', 'ship', 'deliver'],
            'preparing' => ['prepare', 'ship', 'deliver'],
            'ready' => ['prepare', 'ready', 'deliver'],
            'shipped' => ['prepare', 'ready', 'ship'],
            'delivered' => ['prepare', 'ready', 'ship', 'deliver'],
            'cancelled' => ['prepare', 'ready', 'ship', 'deliver'],
            'pending' => ['ready', 'ship', 'deliver'],
        ];
        $courier = $this->person('delivery');
        foreach ($cases as $status => $steps) {
            $order = $this->order($status);
            foreach ($steps as $step) {
                $body = $step === 'ship' ? ['delivery_user_id' => $courier->id] : [];
                $this->patchJson("/admin/orders/{$order->id}/$step", $body)->assertStatus(422);
            }
            $this->assertSame($status, $order->fresh()->status);
            $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->count());
        }
    }

    public function test_there_is_no_manual_confirm_and_no_admin_route_can_confirm_or_mark_as_paid(): void
    {
        $admin = $this->person('admin');
        $order = $this->order('pending');

        $this->actingAs($admin)->patchJson("/admin/orders/{$order->id}/confirm")->assertNotFound();
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'admin')) {
                $this->assertDoesNotMatchRegularExpression('/confirm|paid|payment/i', $route->uri(), "an admin route could mark payments: {$route->uri()}");
            }
        }

        // Every admin route that takes an order, with every payload that could smuggle a status or a payment.
        $payloads = [[], ['status' => 'confirmed'], ['payment_status' => 'paid', 'status' => 'confirmed', 'paid_at' => now()->toDateTimeString()], ['expected_status' => 'pending', 'note' => 'x']];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin') || ! str_contains($route->uri(), '{order}') || in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = '/'.str_replace('{order}', (string) $order->id, $route->uri());
            foreach ($payloads as $payload) {
                $this->actingAs($admin)->json($route->methods()[0], $uri, $payload);
                $fresh = $order->fresh();
                $this->assertNotSame('confirmed', $fresh->status, "{$route->uri()} confirmed the order");
                $this->assertSame('pending', $fresh->payment_status, "{$route->uri()} marked the order as paid");
                $this->assertNull($fresh->paid_at);
            }
            $order->forceFill(['status' => 'pending', 'payment_status' => 'pending'])->save();
        }
    }

    public function test_pending_can_start_preparing_only_while_payment_is_not_required(): void
    {
        $dispatch = $this->person('dispatch');
        $order = $this->order('pending');
        $this->actingAs($dispatch);

        config(['shop.require_payment_before_fulfillment' => true]);
        $this->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => $order->fresh()->status])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);

        config(['shop.require_payment_before_fulfillment' => false]);
        $this->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => $order->fresh()->status])->assertOk()->assertJsonPath('order.status', 'preparing');
        $this->assertSame([['pending', 'preparing']], OrderStatusHistory::where('order_id', $order->id)->get()->map(fn ($step) => [$step->from_status, $step->to_status])->all());
    }

    // ---- PaymentService and the events ----

    public function test_the_payment_service_moves_pending_to_confirmed_once_and_fires_both_events(): void
    {
        Event::fake([OrderPaid::class, OrderStatusChanged::class]);
        $order = $this->order('pending');

        $paid = app(PaymentService::class)->markPaid($order);

        $this->assertSame(['confirmed', 'paid'], [$paid->status, $paid->payment_status]);
        $this->assertNotNull($paid->fresh()->paid_at);
        $this->assertNotNull($paid->fresh()->confirmed_at);
        $this->assertSame([['pending', 'confirmed', null]], OrderStatusHistory::where('order_id', $order->id)->get()->map(fn ($step) => [$step->from_status, $step->to_status, $step->user_id])->all());
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(OrderStatusChanged::class, fn ($event) => [$event->from, $event->to, $event->by] === ['pending', 'confirmed', null] && $event->order->is($order));

        app(PaymentService::class)->markPaid($order); // a repeated webhook changes nothing and says nothing
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    public function test_an_order_already_in_preparation_keeps_its_stage_when_the_payment_arrives(): void
    {
        $order = $this->order('preparing');
        $paid = app(PaymentService::class)->markPaid($order);
        $this->assertSame(['preparing', 'paid'], [$paid->status, $paid->payment_status]);
        $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_the_status_changed_event_carries_the_order_both_states_and_the_user(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $dispatch = $this->person('dispatch');
        $order = $this->order('confirmed');
        $this->actingAs($dispatch)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => $order->fresh()->status])->assertOk();

        Event::assertDispatched(OrderStatusChanged::class, fn ($event) => $event->order->id === $order->id && $event->from === 'confirmed' && $event->to === 'preparing' && $event->by->is($dispatch));
    }

    public function test_events_fire_after_the_commit_and_never_when_the_step_fails(): void
    {
        $seen = [];
        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event) use (&$seen) {
            $seen[] = [Order::find($event->order->id)->status, DB::transactionLevel()]; // the new status is already stored; the test wraps everything in one transaction (level 1)
        });
        $dispatch = $this->person('dispatch');
        $order = $this->order('ready');
        $this->actingAs($dispatch);

        $this->patchJson("/admin/orders/{$order->id}/ship", ['expected_status' => $order->fresh()->status, ])->assertStatus(422); // no courier: rolled back
        $this->assertSame([], $seen);

        $this->patchJson("/admin/orders/{$order->id}/ship", ['expected_status' => $order->fresh()->status, 'carrier' => 'Servientrega'])->assertOk();
        $this->assertSame([['shipped', 1]], $seen);
    }

    // ---- Permissions ----

    public function test_who_can_take_each_step(): void
    {
        $courier = $this->person('delivery');
        $mine = $this->order('shipped', ['assigned_delivery_user_id' => $courier->id]);
        $theirs = $this->order('shipped', ['assigned_delivery_user_id' => $this->person('delivery')->id]);
        $confirmed = $this->order('confirmed');
        $preparing = $this->order('preparing');
        $ready = $this->order('ready');

        $this->actingAs($courier);
        $this->patchJson("/admin/orders/{$confirmed->id}/prepare", ['expected_status' => $confirmed->fresh()->status])->assertForbidden();
        $this->patchJson("/admin/orders/{$preparing->id}/ready", ['expected_status' => $preparing->fresh()->status])->assertForbidden();
        $this->patchJson("/admin/orders/{$ready->id}/ship", ['expected_status' => $ready->fresh()->status, 'carrier' => 'X'])->assertForbidden();
        $this->patchJson("/admin/orders/{$theirs->id}/deliver", ['expected_status' => $theirs->fresh()->status])->assertForbidden();
        $this->assertSame('shipped', $theirs->fresh()->status);
        $this->patchJson("/admin/orders/{$mine->id}/deliver", ['expected_status' => $mine->fresh()->status])->assertOk();

        foreach (['admin', 'dispatch'] as $role) {
            $this->actingAs($this->person($role));
            $this->patchJson('/admin/orders/'.$this->order('confirmed')->id.'/prepare', ['expected_status' => 'confirmed'])->assertOk();
            $this->patchJson('/admin/orders/'.$this->order('preparing')->id.'/ready', ['expected_status' => 'preparing'])->assertOk();
            $this->patchJson('/admin/orders/'.$this->order('ready')->id.'/ship', ['carrier' => 'X', 'expected_status' => 'ready'])->assertOk();
            $this->patchJson('/admin/orders/'.$this->order('shipped', ['assigned_delivery_user_id' => $courier->id])->id.'/deliver', ['expected_status' => 'shipped'])->assertOk();
        }
    }

    public function test_pedidos_and_hoy_offer_the_same_next_step_and_the_same_endpoints(): void
    {
        $admin = $this->person('admin');
        $dispatch = $this->person('dispatch');
        $courier = $this->person('delivery');
        $orders = ['confirmed' => 'prepare', 'preparing' => 'ready', 'ready' => 'ship', 'shipped' => 'deliver'];
        foreach ($orders as $status => $step) {
            $this->order($status, $status === 'shipped' ? ['assigned_delivery_user_id' => $courier->id] : []);
        }

        foreach ([$admin, $dispatch] as $user) {
            $this->actingAs($user);
            $today = collect($this->getJson('/admin/today/data')->assertOk()->json('sections'))->flatten(1);
            $this->assertCount(4, $today);
            foreach ($today as $card) {
                $detail = $this->getJson("/admin/orders/{$card['id']}/detail")->assertOk()->json('order');
                $this->assertSame($card['next'], $detail['next']);
                $this->assertSame($orders[$card['status']], $card['next']['step']);
                $this->assertTrue(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'admin/orders/{order}/'.$card['next']['step']));
            }
        }
        // A delivery person gets a next step only for their own order.
        $this->actingAs($courier);
        $card = $this->getJson('/admin/today/data')->json('sections.shipping.0');
        $this->assertSame('deliver', $card['next']['step']);
    }

    // ---- Hoy ----

    public function test_hoy_has_four_sections_oldest_first_and_counters_equal_the_lists(): void
    {
        $this->actingAs($this->person('admin'));
        $newer = $this->order('confirmed', ['status_changed_at' => now()->subMinutes(10)]);
        $older = $this->order('confirmed', ['status_changed_at' => now()->subHours(3)]);
        $pending = $this->order('pending', ['status_changed_at' => now()->subHour()]);
        $this->order('preparing');
        $this->order('ready');
        $this->order('ready');
        $this->order('shipped');
        $this->order('delivered');
        $this->order('cancelled');

        $json = $this->getJson('/admin/today/data')->assertOk()->json();
        $this->assertSame(['new', 'preparing', 'ready', 'shipping'], array_keys($json['sections']));
        $this->assertSame([$older->id, $pending->id, $newer->id], collect($json['sections']['new'])->pluck('id')->all()); // pending shows while payment is not required
        $this->assertSame(['new' => 3, 'preparing' => 1, 'ready' => 2, 'shipping' => 1], $json['counts']);
        foreach ($json['sections'] as $key => $cards) {
            $this->assertCount($json['counts'][$key], $cards);
        }
        $this->assertSame(70000, $json['sections']['new'][0]['total_cop']); // admin sees the total
        $this->assertSame('Kit Degustación 2×125 g · Molido', $json['sections']['new'][0]['items'][0]['name']);
        $this->assertSame(2, $json['sections']['new'][0]['items'][0]['quantity']);
        $this->assertSame('Dejar con el portero', $json['sections']['new'][0]['order_notes']);

        config(['shop.require_payment_before_fulfillment' => true]);
        $this->assertSame(0, $this->getJson('/admin/today/data')->json('counts.new')); // none is paid: nothing to start
        DB::table('orders')->update(['payment_status' => 'paid']);
        $this->assertSame(2, $this->getJson('/admin/today/data')->json('counts.new')); // pending never appears
    }

    public function test_hoy_does_not_run_a_query_per_order(): void
    {
        $this->actingAs($this->person('admin'));
        foreach (['confirmed', 'preparing', 'ready'] as $status) {
            for ($i = 0; $i < 4; $i++) {
                $this->order($status);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/admin/today/data')->assertOk();
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog())); // session, orders, items, couriers... never one per order
    }

    public function test_dispatch_sees_four_sections_without_prices_coupon_payments_or_email(): void
    {
        $courier = $this->person('delivery');
        $this->actingAs($this->person('dispatch'));
        foreach (['confirmed', 'preparing', 'ready', 'shipped'] as $status) {
            $this->order($status);
        }
        $response = $this->getJson('/admin/today/data')->assertOk();
        $this->assertSame(['new' => 1, 'preparing' => 1, 'ready' => 1, 'shipping' => 1], $response->json('counts'));
        foreach (['customer_email', 'secreto', 'total_cop', 'subtotal_cop', 'unit_price_cop', 'discount_cop', 'coupon_code', 'SECRETO10', 'payment', '73450', '70000', 'paid_at'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent(), "dispatch got $forbidden");
        }
        $this->assertSame([$courier->id], collect($response->json('couriers'))->pluck('id')->all()); // for the "Enviar" panel
    }

    public function test_delivery_only_gets_its_own_shipped_orders_and_counters(): void
    {
        $courier = $this->person('delivery');
        $other = $this->person('delivery');
        $mine = [$this->order('shipped', ['assigned_delivery_user_id' => $courier->id]), $this->order('shipped', ['assigned_delivery_user_id' => $courier->id])];
        $this->order('shipped', ['assigned_delivery_user_id' => $other->id]);
        $this->order('confirmed');
        $this->order('ready');
        $this->order('delivered', ['assigned_delivery_user_id' => $courier->id]);

        $response = $this->actingAs($courier)->getJson('/admin/today/data')->assertOk();
        $this->assertSame(['shipping'], array_keys($response->json('sections')));
        $this->assertSame(['shipping' => 2], $response->json('counts'));
        $this->assertEqualsCanonicalizing(collect($mine)->pluck('id')->all(), collect($response->json('sections.shipping'))->pluck('id')->all());
        $this->assertSame([], $response->json('couriers'));
        foreach (['customer_email', 'total_cop', 'coupon_code', 'payment', 'SECRETO10', 'secreto'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_hoy_needs_a_session_and_the_permission(): void
    {
        $this->getJson('/admin/today/data')->assertUnauthorized();
        $this->get('/admin/today')->assertRedirect('/admin/login');
        foreach (['admin', 'dispatch', 'delivery'] as $role) {
            $this->actingAs($this->person($role))->get('/admin/today')->assertOk()->assertSee('<title>', false);
            $this->actingAs($this->person($role))->getJson('/admin/today/data')->assertOk();
        }
    }

    // ---- Two people at once ----

    public function test_when_somebody_else_already_moved_the_order_nothing_is_saved_and_the_name_is_shown(): void
    {
        $first = $this->person('dispatch', ['name' => 'maría ROJAS']);
        $second = $this->person('dispatch');
        $order = $this->order('confirmed');

        $this->actingAs($first)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => 'confirmed'])->assertOk();

        $this->actingAs($second)->patchJson("/admin/orders/{$order->id}/prepare", ['expected_status' => 'confirmed'])
            ->assertStatus(409)->assertJsonPath('message', 'María Rojas ya movió este pedido a "En preparación".')->assertJsonPath('status', 'preparing');
        $this->actingAs($second)->patchJson("/admin/orders/{$order->id}/ship", ['carrier' => 'X', 'expected_status' => 'confirmed'])->assertStatus(409);
        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    // ---- Pedidos ----

    public function test_pedidos_filters_offer_the_new_states_and_count_delivered_as_completed(): void
    {
        $this->actingAs($this->person('admin'));
        foreach (array_keys(OrderStatusService::LABELS) as $status) {
            $this->order($status);
        }
        $json = $this->getJson('/admin/orders/data')->assertOk()->json();
        $this->assertSame(array_keys(OrderStatusService::LABELS), $json['options']['statuses']);
        $this->assertSame(1, $json['summary']['completed']);
        $this->assertSame(1, $this->getJson('/admin/orders/data?status=ready')->json('meta.total'));
    }

    public function test_the_roles_land_on_hoy_and_the_old_screens_are_gone(): void
    {
        $this->assertSame('/admin/today', \App\Enums\Role::Admin->home());
        $this->assertSame('/admin/today', \App\Enums\Role::Dispatch->home());
        $this->assertSame('/admin/today', \App\Enums\Role::Delivery->home());
        $this->get('/admin/deliveries')->assertStatus(301)->assertRedirect('/admin/today');
        $this->actingAs($this->person('dispatch'))->get('/admin/inventory')->assertNotFound();
        $this->actingAs($this->person('dispatch'))->getJson('/admin/session')->assertJsonPath('permissions', ['today.view', 'orders.list', 'orders.view_all', 'orders.prepare', 'orders.ship', 'orders.deliver', 'orders.print']);
        $this->actingAs($this->person('delivery'))->getJson('/admin/session')->assertJsonPath('permissions', ['today.view', 'orders.list', 'orders.view_assigned', 'orders.deliver']);
    }
}
