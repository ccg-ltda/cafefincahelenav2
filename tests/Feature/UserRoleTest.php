<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\UserService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function person(string $role = 'admin', array $attributes = []): User
    {
        $n = ++self::$n;

        return User::factory()->create($attributes + ['role' => $role, 'name' => ucfirst($role)." Persona $n", 'username' => "$role.persona$n", 'email' => "$role$n@example.com"]);
    }

    private function order(string $status = 'pending', array $override = []): Order
    {
        $n = ++self::$n;
        $order = Order::create($override + [
            'reference' => sprintf('CFH-%012d', $n), 'customer_name' => "Cliente $n", 'customer_email' => "secreto$n@cliente.com", 'customer_phone' => '3001234567',
            'delivery_address' => 'Calle 45 # 12-34', 'delivery_details' => 'Apto 301', 'order_notes' => 'Dejar con el portero', 'delivery_city' => 'Cúcuta', 'delivery_department' => 'Norte de Santander',
            'subtotal_cop' => 73450, 'shipping_cop' => 0, 'discount_cop' => 3450, 'coupon_code' => 'SECRETO10', 'total_cop' => 70000, 'currency' => 'COP', 'status' => $status, 'payment_status' => 'pending',
            'terms_accepted_at' => now(), 'data_consent_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'FH-001', 'name' => 'Café Premium 250 g', 'quantity' => 2, 'unit_price_cop' => 36725, 'subtotal_cop' => 73450]);

        return $order;
    }

    // ---- The matrix ----

    public function test_the_permission_matrix_is_the_single_source_and_gates_follow_it(): void
    {
        foreach (Role::cases() as $role) {
            $user = $this->person($role->value);
            foreach (Permissions::ALL as $permission) {
                $this->assertSame(Permissions::has($role, $permission), $user->can($permission), "{$role->value} / $permission");
            }
        }
        $this->assertSame(Permissions::ALL, Permissions::for(Role::Admin));
        $inactive = $this->person('admin', ['is_active' => false]);
        $this->assertSame([], $inactive->permissions());
        $this->assertFalse($inactive->can('users.manage'));
        $this->assertSame(['/admin/today', '/admin/today', '/admin/today'], array_map(fn (Role $role) => $role->home(), Role::cases()));
    }

    public function test_every_admin_endpoint_is_protected_by_a_permission(): void
    {
        $anyStaff = ['admin/session', 'admin/logout', 'admin/password'];
        $public = ['admin/login', 'admin/{path}', 'admin/deliveries', 'admin/recover-password', 'admin/password-link', 'admin/password-link/check']; // the last one is only a 301 to /admin/today
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'admin') || in_array($uri, $public, true) || in_array($uri, $anyStaff, true)) {
                continue;
            }
            $guards = collect($route->gatherMiddleware());
            $isPage = $route->methods()[0] === 'GET' && $guards->contains(fn ($middleware) => str_contains((string) $middleware, 'RequirePagePermission'));
            $hasCan = $guards->contains(fn ($middleware) => str_starts_with((string) $middleware, 'can:'));
            $isFreePage = $uri === 'admin/change-password';
            $this->assertTrue($isPage || $hasCan || $isFreePage, "Unprotected admin route: {$route->methods()[0]} /$uri");
            $this->assertTrue($guards->contains(fn ($middleware) => str_contains((string) $middleware, 'EnsureStaff')), "No EnsureStaff: /$uri");
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> method, uri, permission */
    private function endpoints(): array
    {
        return [
            ['get', '/admin/dashboard/summary', 'dashboard.view'],
            ['get', '/admin/orders/data', 'orders.list'], ['get', '/admin/orders/999/detail', 'orders.list'], ['get', '/admin/today/data', 'today.view'],
            ['patch', '/admin/orders/999/prepare', 'orders.prepare'], ['patch', '/admin/orders/999/ready', 'orders.prepare'],
            ['patch', '/admin/orders/999/ship', 'orders.ship'], ['patch', '/admin/orders/999/reassign', 'orders.ship'], ['patch', '/admin/orders/999/deliver', 'orders.deliver'],

            ['get', '/admin/products/data', 'products.manage'], ['get', '/admin/products/categories/data', 'products.manage'], ['post', '/admin/products/categories', 'products.manage'],
            ['post', '/admin/products', 'products.manage'], ['put', '/admin/products/999', 'products.manage'], ['patch', '/admin/products/999/status', 'products.manage'],
            ['get', '/admin/products/999/detail', 'products.manage'], ['post', '/admin/products/999/variants', 'products.manage'],
            ['post', '/admin/products/999/variants/999/stock', 'inventory.edit'],
            ['get', '/admin/promotions/data', 'promotions.manage'], ['post', '/admin/promotions', 'promotions.manage'], ['delete', '/admin/promotions/999', 'promotions.manage'],
            ['get', '/admin/coupons/data', 'coupons.manage'], ['post', '/admin/coupons', 'coupons.manage'], ['delete', '/admin/coupons/999', 'coupons.manage'],
            ['get', '/admin/customers/data', 'customers.view'], ['get', '/admin/customers/export', 'customers.view'], ['patch', '/admin/customers/999/marketing-opt-out', 'customers.manage'],
            ['get', '/admin/whatsapp/data', 'whatsapp.manage'], ['post', '/admin/whatsapp/requests', 'whatsapp.manage'], ['patch', '/admin/whatsapp/requests/999/take', 'whatsapp.manage'],
            ['get', '/admin/activity/data', 'activity.view'], ['get', '/admin/activity/export', 'activity.view'], ['get', '/admin/activity/999/detail', 'activity.view'],
            ['get', '/admin/users/data', 'users.manage'], ['get', '/admin/users/999/detail', 'users.manage'], ['post', '/admin/users', 'users.manage'],
            ['put', '/admin/users/999', 'users.manage'], ['patch', '/admin/users/999/deactivate', 'users.manage'], ['patch', '/admin/users/999/reactivate', 'users.manage'],
            ['post', '/admin/users/999/reset-password', 'users.manage'], ['get', '/admin/users/username-check', 'users.manage'],
        ];
    }

    public function test_every_endpoint_answers_by_role_and_without_a_session(): void
    {
        $people = ['admin' => $this->person('admin'), 'dispatch' => $this->person('dispatch'), 'delivery' => $this->person('delivery')];
        foreach ($this->endpoints() as [$method, $uri]) {
            $this->assertSame(401, $this->json($method, $uri)->getStatusCode(), "guest $method $uri"); // no session
        }
        foreach ($this->endpoints() as [$method, $uri, $permission]) {
            foreach ($people as $role => $user) {
                $status = $this->actingAs($user)->json($method, $uri)->getStatusCode();
                if (Permissions::has($role, $permission)) {
                    $this->assertNotContains($status, [401, 403], "$role $method $uri should be allowed (got $status)");
                } else {
                    $this->assertSame(403, $status, "$role $method $uri should be 403 (got $status)");
                }
            }
        }
        auth()->logout();
    }

    public function test_pages_answer_the_shell_with_403_and_the_page_title_to_a_person_without_permission(): void
    {
        $dispatch = $this->person('dispatch');
        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->actingAs($dispatch)->get('/admin/users')->assertForbidden()->assertSee('noindex', false)->assertSee('Sin acceso', false);
        $this->actingAs($dispatch)->get('/admin/orders')->assertOk();
        $this->actingAs($dispatch)->get('/admin/today')->assertOk();
        $this->actingAs($dispatch)->get('/admin/inventory')->assertNotFound(); // the read-only stock screen is gone
        $this->actingAs($this->person('delivery'))->get('/admin/today')->assertOk();
        $this->actingAs($this->person('delivery'))->get('/admin/orders')->assertOk(); // their own orders
        $this->actingAs($this->person('delivery'))->get('/admin/products')->assertForbidden();
    }

    // ---- Orders: hidden fields ----

    public function test_dispatch_never_receives_email_prices_coupon_or_payments(): void
    {
        $dispatch = $this->person('dispatch');
        $order = $this->order('confirmed');

        $list = $this->actingAs($dispatch)->getJson('/admin/orders/data')->assertOk()->json();
        $detail = $this->getJson("/admin/orders/{$order->id}/detail")->assertOk()->json();
        foreach ([json_encode($list), json_encode($detail)] as $json) {
            foreach (['customer_email', 'secreto', 'total_cop', 'subtotal_cop', 'unit_price_cop', 'discount_cop', 'coupon_code', 'SECRETO10', 'payment', '73450', '36725', '70000', 'paid_at'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $json, "dispatch got $forbidden");
            }
        }
        $this->assertSame(['Cúcuta', 'Calle 45 # 12-34', 'Dejar con el portero', 2, 'Café Premium 250 g'], [$detail['order']['delivery_city'], $detail['order']['delivery_address'], $detail['order']['order_notes'], $detail['order']['items'][0]['quantity'], $detail['order']['items'][0]['name']]);
    }

    public function test_the_administrator_still_receives_everything(): void
    {
        $order = $this->order('confirmed');
        $detail = $this->actingAs($this->person('admin'))->getJson("/admin/orders/{$order->id}/detail")->assertOk()->json('order');
        $this->assertSame(['SECRETO10', 70000, 3450, "secreto{$this->lastN()}@cliente.com", 36725], [$detail['coupon_code'], $detail['total_cop'], $detail['discount_cop'], $detail['customer_email'], $detail['items'][0]['unit_price_cop']]);
        $this->assertArrayHasKey('payments', $detail);
    }

    private function lastN(): int
    {
        return self::$n - 1; // order() increments once before building the email
    }

    public function test_the_search_of_a_role_without_email_access_does_not_look_at_emails(): void
    {
        $this->order('pending', ['customer_name' => 'Pedro Pérez', 'customer_email' => 'zeta.unico@secreto.com']);

        $this->actingAs($this->person('dispatch'));
        $this->assertCount(0, $this->getJson('/admin/orders/data?search=zeta.unico')->json('data'));
        $this->assertCount(1, $this->getJson('/admin/orders/data?search=Pedro')->json('data'));

        $this->actingAs($this->person('admin'));
        $this->assertCount(1, $this->getJson('/admin/orders/data?search=zeta.unico')->json('data'));
    }

    public function test_dispatch_only_sees_active_valid_orders(): void
    {
        $active = [$this->order('pending'), $this->order('confirmed'), $this->order('preparing'), $this->order('ready'), $this->order('shipped')];
        $delivered = $this->order('delivered');
        $cancelled = $this->order('cancelled');

        $this->actingAs($this->person('dispatch'));
        $ids = collect($this->getJson('/admin/orders/data')->json('data'))->pluck('id')->all();
        foreach ($active as $order) {
            $this->assertContains($order->id, $ids);
        }
        $this->assertNotContains($delivered->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
        $this->getJson("/admin/orders/{$delivered->id}/detail")->assertNotFound();

        config(['shop.require_payment_before_fulfillment' => true]);
        $this->assertCount(0, $this->getJson('/admin/orders/data')->json('data')); // none is paid
        $this->actingAs($this->person('admin'));
        $this->assertGreaterThanOrEqual(7, count($this->getJson('/admin/orders/data')->json('data')));
    }

    public function test_delivery_only_sees_its_assigned_orders_in_pedidos_and_cannot_open_others(): void
    {
        $courier = $this->person('delivery');
        $other = $this->person('delivery');
        $mine = $this->order('shipped', ['assigned_delivery_user_id' => $courier->id]);
        $done = $this->order('delivered', ['assigned_delivery_user_id' => $courier->id]);
        $theirs = $this->order('shipped', ['assigned_delivery_user_id' => $other->id]);
        $unassigned = $this->order('preparing');

        $this->actingAs($courier);
        $list = $this->getJson('/admin/orders/data')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$mine->id, $done->id], collect($list)->pluck('id')->all()); // delivered ones stay as their history
        $this->assertSame(['shipped', 'delivered'], $this->getJson('/admin/orders/data')->json('options.statuses'));
        foreach (['customer_email', 'total_cop', 'coupon_code', 'payments', 'unit_price_cop', 'SECRETO10', 'secreto'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, json_encode($list));
            $this->assertStringNotContainsString($forbidden, $this->getJson("/admin/orders/{$mine->id}/detail")->assertOk()->getContent());
        }
        $this->getJson("/admin/orders/{$done->id}/detail")->assertOk();
        $this->getJson("/admin/orders/{$theirs->id}/detail")->assertForbidden(); // changing the id does not open another order
        $this->getJson("/admin/orders/{$unassigned->id}/detail")->assertForbidden();
    }

    public function test_deliveries_moved_to_hoy_with_a_permanent_redirect(): void
    {
        $this->get('/admin/deliveries')->assertStatus(301)->assertRedirect('/admin/today');
        $this->actingAs($this->person('delivery'))->getJson('/admin/deliveries/data')->assertNotFound();
        $this->actingAs($this->person('dispatch'))->getJson('/admin/inventory/data')->assertNotFound();
        $this->assertNotContains('inventory.view', Permissions::ALL);
        $this->assertSame(['inventory.edit'], array_values(array_filter(Permissions::for(Role::Admin), fn ($permission) => str_starts_with($permission, 'inventory.'))));
        $this->assertSame([], array_values(array_filter(Permissions::for(Role::Dispatch), fn ($permission) => str_starts_with($permission, 'inventory.'))));
    }

    // ---- OrderStatusService ----

    public function test_the_full_flow_with_a_delivery_person_records_history_and_the_historial(): void
    {
        $dispatch = $this->person('dispatch');
        $courier = $this->person('delivery');
        $order = $this->order('pending');
        app(PaymentService::class)->markPaid($order); // the payment, not the panel, confirms
        $this->actingAs($dispatch);

        $this->patchJson("/admin/orders/{$order->id}/prepare", ['note' => 'Empacando'])->assertOk()->assertJsonPath('order.status', 'preparing');
        $this->patchJson("/admin/orders/{$order->id}/ready")->assertOk()->assertJsonPath('order.status', 'ready');
        $this->patchJson("/admin/orders/{$order->id}/ship", ['delivery_user_id' => $courier->id])->assertOk()->assertJsonPath('order.assigned_delivery.id', $courier->id);

        $this->actingAs($courier)->patchJson("/admin/orders/{$order->id}/deliver", ['note' => 'Recibió el portero'])->assertOk()->assertJsonPath('order.status', 'delivered');

        $fresh = $order->fresh();
        $this->assertSame(['delivered', 'Recibió el portero'], [$fresh->status, $fresh->delivery_note]);
        foreach (['confirmed_at', 'shipped_at', 'delivered_at', 'status_changed_at'] as $column) {
            $this->assertNotNull($fresh->$column, $column);
        }
        $steps = OrderStatusHistory::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame([['pending', 'confirmed'], ['confirmed', 'preparing'], ['preparing', 'ready'], ['ready', 'shipped'], ['shipped', 'delivered']], $steps->map(fn ($step) => [$step->from_status, $step->to_status])->all());
        $this->assertSame([null, 'Empacando', null, null, 'Recibió el portero'], $steps->pluck('note')->all());
        $this->assertSame([null, $dispatch->id, $dispatch->id, $dispatch->id, $courier->id], $steps->pluck('user_id')->all());

        $rows = ActivityLog::query()->where('module', 'orders')->where('action', 'status_changed')->orderBy('id')->get();
        $this->assertCount(5, $rows);
        $this->assertSame(['status' => ['shipped', 'delivered']], $rows[4]->getAttribute('changes'));
        $this->assertSame('Recibió el portero', $rows[4]->context['note']);
        $this->assertSame("{$courier->name} · Solo entrega", $rows[4]->actor_label); // role shown next to the name
        $this->assertSame("{$dispatch->name} · Despacho y entrega", $rows[1]->actor_label);
        $this->assertSame('Sistema', $rows[0]->actor_label); // the payment
    }

    public function test_shipping_needs_exactly_a_courier_or_a_carrier(): void
    {
        $dispatch = $this->person('dispatch');
        $courier = $this->person('delivery');
        $order = $this->order('ready');
        $this->actingAs($dispatch);

        $this->patchJson("/admin/orders/{$order->id}/ship", [])->assertStatus(422);
        $this->patchJson("/admin/orders/{$order->id}/ship", ['delivery_user_id' => $courier->id, 'carrier' => 'Servientrega'])->assertStatus(422);
        $this->patchJson("/admin/orders/{$order->id}/ship", ['delivery_user_id' => $this->person('dispatch')->id])->assertStatus(422); // not a delivery person
        $inactive = $this->person('delivery', ['is_active' => false]);
        $this->patchJson("/admin/orders/{$order->id}/ship", ['delivery_user_id' => $inactive->id])->assertStatus(422);
        $this->assertSame('ready', $order->fresh()->status); // nothing changed, no history, no Historial row
        $this->assertSame(0, OrderStatusHistory::count());
        $this->assertSame(0, ActivityLog::where('module', 'orders')->count());

        $this->patchJson("/admin/orders/{$order->id}/ship", ['carrier' => 'Servientrega', 'tracking_number' => 'SV123'])->assertOk();
        $this->assertSame(['shipped', 'Servientrega', 'SV123', null], [$order->fresh()->status, $order->fresh()->carrier, $order->fresh()->tracking_number, $order->fresh()->assigned_delivery_user_id]);
    }

    public function test_invalid_transitions_and_unpaid_orders_are_refused(): void
    {
        $dispatch = $this->person('dispatch');
        $order = $this->order('pending');
        $this->actingAs($dispatch);

        $this->patchJson("/admin/orders/{$order->id}/ready")->assertStatus(422); // pending -> ready skips steps
        $this->patchJson("/admin/orders/{$order->id}/deliver")->assertStatus(422);
        $cancelled = $this->order('cancelled');
        $this->patchJson("/admin/orders/{$cancelled->id}/prepare")->assertStatus(422);

        config(['shop.require_payment_before_fulfillment' => true]);
        $this->patchJson("/admin/orders/{$order->id}/prepare")->assertStatus(422)->assertJsonPath('errors.status.0', 'No se puede pasar el pedido de "Pendiente de pago" a "En preparación".');
        $confirmed = $this->order('confirmed');
        $this->patchJson("/admin/orders/{$confirmed->id}/prepare")->assertStatus(422)->assertJsonPath('errors.status.0', 'El pedido debe estar pagado antes de pasar a "En preparación".');
        $confirmed->forceFill(['payment_status' => 'paid'])->save();
        $this->patchJson("/admin/orders/{$confirmed->id}/prepare")->assertOk();
    }

    public function test_delivery_cannot_deliver_orders_assigned_to_someone_else_or_run_other_steps(): void
    {
        $courier = $this->person('delivery');
        $theirs = $this->order('shipped', ['assigned_delivery_user_id' => $this->person('delivery')->id]);
        $mine = $this->order('shipped', ['assigned_delivery_user_id' => $courier->id]);

        $this->actingAs($courier);
        $this->patchJson("/admin/orders/{$theirs->id}/deliver")->assertForbidden();
        $this->assertSame('shipped', $theirs->fresh()->status);
        $this->patchJson("/admin/orders/{$mine->id}/prepare")->assertForbidden();
        $this->patchJson("/admin/orders/{$mine->id}/ship", ['carrier' => 'X'])->assertForbidden();
        $this->patchJson("/admin/orders/{$mine->id}/deliver")->assertOk();
    }

    public function test_the_status_service_is_the_only_writer_of_orders_status(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_ends_with($file->getFilename(), 'OrderStatusService.php') && preg_match("/['\"]status['\"]\s*=>\s*['\"](confirmed|preparing|ready|shipped|delivered|cancelled)['\"]/", file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
        $this->assertSame([], $offenders);
        $this->assertSame('confirmed', OrderStatusService::FLOW['pending']);
    }

    public function test_reassigning_a_shipped_order_moves_it_and_is_logged(): void
    {
        $a = $this->person('delivery');
        $b = $this->person('delivery');
        $order = $this->order('shipped', ['assigned_delivery_user_id' => $a->id]);
        $this->actingAs($this->person('dispatch'))->patchJson("/admin/orders/{$order->id}/reassign", ['delivery_user_id' => $b->id])->assertOk();

        $this->assertSame($b->id, $order->fresh()->assigned_delivery_user_id);
        $this->assertSame(['assigned_delivery_user_id' => [$a->name, $b->name]], ActivityLog::where('module', 'orders')->where('action', 'updated')->first()->getAttribute('changes'));
        $this->assertSame(0, OrderStatusHistory::count());
    }

    // ---- Login and sessions ----

    private function login(string $login, string $password)
    {
        return $this->postJson('/admin/login', ['login' => $login, 'password' => $password]);
    }

    public function test_login_works_with_username_or_email_and_returns_the_home_of_the_role(): void
    {
        $this->person('dispatch', ['username' => 'carlos.rojas', 'email' => 'Carlos@Example.com', 'password' => 'Clave-segura-1']);

        $this->login('CARLOS.ROJAS', 'Clave-segura-1')->assertOk()->assertJsonPath('home', '/admin/today');
        $this->postJson('/admin/logout')->assertOk();
        $this->login('carlos@example.com', 'Clave-segura-1')->assertOk();
        $this->assertNotNull(User::where('username', 'carlos.rojas')->first()->last_login_at);
        $this->postJson('/admin/logout')->assertOk();
        $this->login('carlos.rojas', 'otra-clave-123')->assertStatus(422)->assertJsonPath('errors.login.0', 'Las credenciales no son válidas.');
        $this->login('nadie', 'otra-clave-123')->assertStatus(422)->assertJsonPath('errors.login.0', 'Las credenciales no son válidas.');
    }

    public function test_a_person_without_email_can_sign_in(): void
    {
        $this->person('delivery', ['username' => 'luis.mora', 'email' => null, 'password' => 'Clave-segura-1']);
        $this->login('luis.mora', 'Clave-segura-1')->assertOk()->assertJsonPath('home', '/admin/today');
    }

    public function test_a_deactivated_person_cannot_sign_in_and_is_logged_with_the_note(): void
    {
        $this->person('dispatch', ['username' => 'ana.baja', 'password' => 'Clave-segura-1', 'is_active' => false]);

        $this->login('ana.baja', 'Clave-segura-1')->assertStatus(422)->assertJsonPath('errors.login.0', 'Las credenciales no son válidas.');
        $row = ActivityLog::where('action', 'login_failed')->first();
        $this->assertSame('usuario desactivado', $row->context['note']);
        $this->assertSame('a***', $row->context['login']);
        $this->assertStringNotContainsString('Clave-segura', json_encode($row->getAttributes()));
    }

    public function test_session_returns_the_permissions_of_the_role(): void
    {
        $this->actingAs($this->person('dispatch'));
        $json = $this->getJson('/admin/session')->assertOk()->json();
        $this->assertSame(Permissions::for('dispatch'), $json['permissions']);
        $this->assertSame(['dispatch', 'Despacho y entrega', '/admin/today', false], [$json['role'], $json['role_label'], $json['home'], $json['must_change_password']]);
    }

    public function test_a_temporary_password_blocks_everything_until_it_is_changed(): void
    {
        $user = $this->person('dispatch', ['username' => 'nuevo.usuario', 'password' => 'Temporal-12345', 'must_change_password' => true]);

        $this->login('nuevo.usuario', 'Temporal-12345')->assertOk()->assertJsonPath('must_change_password', true);
        $this->getJson('/admin/orders/data')->assertForbidden()->assertJsonPath('must_change_password', true);
        $this->get('/admin/orders')->assertRedirect('/admin/change-password');
        $this->get('/admin/change-password')->assertOk();
        $this->getJson('/admin/session')->assertOk()->assertJsonPath('must_change_password', true);

        $this->postJson('/admin/password', ['password' => 'corta', 'password_confirmation' => 'corta'])->assertStatus(422);
        $this->postJson('/admin/password', ['password' => 'Temporal-12345', 'password_confirmation' => 'Temporal-12345'])->assertStatus(422); // same as the temporary one
        $this->postJson('/admin/password', ['password' => 'Nueva-clave-segura', 'password_confirmation' => 'distinta-clave'])->assertStatus(422);
        $this->postJson('/admin/password', ['password' => 'Nueva-clave-segura', 'password_confirmation' => 'Nueva-clave-segura'])->assertOk();

        $this->getJson('/admin/orders/data')->assertOk(); // this session stays open
        $this->assertFalse($user->fresh()->must_change_password);
        $this->postJson('/admin/logout');
        $this->login('nuevo.usuario', 'Nueva-clave-segura')->assertOk()->assertJsonPath('must_change_password', false);
        $this->postJson('/admin/password', ['password' => 'Otra-clave-segura1', 'password_confirmation' => 'Otra-clave-segura1'])->assertStatus(422); // nothing pending
        $this->assertSame(1, ActivityLog::where('action', 'password_changed')->count());
    }

    public function test_deactivating_or_changing_the_role_closes_open_sessions_at_once(): void
    {
        $admin = $this->person('admin');
        $target = $this->person('dispatch', ['username' => 'sesion.viva', 'password' => 'Clave-segura-1']);
        $this->login('sesion.viva', 'Clave-segura-1')->assertOk();
        $this->getJson('/admin/orders/data')->assertOk();

        app(UserService::class)->update($target, ['name' => $target->name, 'username' => $target->username, 'email' => $target->email, 'role' => 'delivery'], $admin);
        $this->app['auth']->forgetGuards(); // the next request starts without the user cached in memory, like in production
        $this->getJson('/admin/session')->assertUnauthorized(); // the old session of the person is gone

        $this->login('sesion.viva', 'Clave-segura-1')->assertOk();
        $this->getJson('/admin/session')->assertOk()->assertJsonPath('role', 'delivery');
        app(UserService::class)->deactivate($target, $admin);
        $this->app['auth']->forgetGuards(); // the next request starts without the user cached in memory, like in production
        $this->getJson('/admin/session')->assertUnauthorized();
        $this->login('sesion.viva', 'Clave-segura-1')->assertStatus(422);

        $this->login('sesion.viva', 'Clave-segura-1');
        app(UserService::class)->reactivate($target, $admin);
        $this->login('sesion.viva', 'Clave-segura-1')->assertOk();
        app(UserService::class)->resetPassword($target, $admin, 'Temporal-nueva-1');
        $this->app['auth']->forgetGuards(); // the next request starts without the user cached in memory, like in production
        $this->getJson('/admin/session')->assertUnauthorized();
    }

    // ---- Users module ----

    private function newUser(array $override = []): array
    {
        return $override + ['name' => 'Carlos Rojas', 'username' => 'carlos.rojas', 'email' => '', 'role' => 'dispatch', 'password' => 'Temporal-12345'];
    }

    public function test_an_administrator_creates_edits_deactivates_and_resets_people_and_everything_is_logged_without_passwords(): void
    {
        $admin = $this->person('admin');
        $this->actingAs($admin);

        $id = $this->postJson('/admin/users', $this->newUser())->assertCreated()->assertJsonPath('data.must_change_password', true)->assertJsonPath('data.role', 'dispatch')->json('data.id');
        $created = User::find($id);
        $this->assertNull($created->email);
        $this->assertSame($admin->id, $created->created_by);
        $this->assertTrue(Hash::check('Temporal-12345', $created->password));
        $this->assertSame('Usuario: Carlos Rojas (@carlos.rojas)', ActivityLog::where('action', 'created')->first()->subject_label);

        $this->putJson("/admin/users/$id", $this->newUser(['name' => 'Carlos A. Rojas']))->assertOk();
        $this->assertSame(['name' => ['Carlos Rojas', 'Carlos A. Rojas']], ActivityLog::where('module', 'users')->where('action', 'updated')->first()->getAttribute('changes'));

        $this->putJson("/admin/users/$id", $this->newUser(['name' => 'Carlos A. Rojas', 'role' => 'delivery']))->assertOk()->assertJsonPath('data.role_label', 'Solo entrega');
        $this->assertSame(['role' => ['Despacho y entrega', 'Solo entrega']], ActivityLog::where('action', 'role_changed')->first()->getAttribute('changes'));

        $this->patchJson("/admin/users/$id/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/admin/users/$id/reactivate")->assertOk()->assertJsonPath('data.is_active', true);
        $reset = $this->postJson("/admin/users/$id/reset-password", ['password' => 'Temporal-nueva-99'])->assertOk()->json();
        $this->assertSame('Temporal-nueva-99', $reset['temporary_password']);
        $generated = $this->postJson("/admin/users/$id/reset-password")->assertOk()->json('temporary_password');
        $this->assertGreaterThanOrEqual(10, strlen($generated));
        $this->assertTrue(User::find($id)->must_change_password);

        $this->assertSame(['created', 'updated', 'role_changed', 'deactivated', 'reactivated', 'password_reset', 'password_reset'], ActivityLog::where('module', 'users')->orderBy('id')->pluck('action')->all());
        $stored = json_encode(ActivityLog::all()->map->getAttributes());
        foreach (['Temporal-12345', 'Temporal-nueva-99', $generated, '"password"'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertStringNotContainsString('carlos@', $stored);
    }

    public function test_the_list_filters_search_role_and_status_and_never_exposes_passwords(): void
    {
        $this->person('admin', ['name' => 'Ana Admin', 'username' => 'ana.admin']);
        $this->person('dispatch', ['name' => 'Beto Despacho', 'username' => 'beto.d']);
        $this->person('delivery', ['name' => 'Caro Entrega', 'username' => 'caro.e', 'is_active' => false]);
        $this->actingAs(User::where('username', 'ana.admin')->first());

        $json = $this->getJson('/admin/users/data')->assertOk()->json();
        $this->assertSame(['ana.admin', 'beto.d'], collect($json['data'])->pluck('username')->all()); // active by default
        $this->assertSame(['active' => 2, 'admin' => 1, 'dispatch' => 1, 'delivery' => 0], $json['summary']);
        $this->assertStringNotContainsString('"password"', json_encode($json));
        $this->assertCount(1, $this->getJson('/admin/users/data?status=inactive')->json('data'));
        $this->assertCount(3, $this->getJson('/admin/users/data?status=all')->json('data'));
        $this->assertSame(['beto.d'], collect($this->getJson('/admin/users/data?role=dispatch')->json('data'))->pluck('username')->all());
        $this->assertSame(['caro.e'], collect($this->getJson('/admin/users/data?status=all&search=Caro')->json('data'))->pluck('username')->all());
        $this->getJson('/admin/users/data?role=hacker')->assertStatus(422);
    }

    public function test_username_rules_and_live_check(): void
    {
        $this->actingAs($this->person('admin'));
        $this->person('dispatch', ['username' => 'ocupado']);

        $this->assertSame([true, false], [$this->getJson('/admin/users/username-check?username=libre.1')->json('available'), $this->getJson('/admin/users/username-check?username=ocupado')->json('available')]);
        $this->assertFalse($this->getJson('/admin/users/username-check?username=Mal%20Usuario')->json('valid'));
        $this->postJson('/admin/users', $this->newUser(['username' => 'OCUPADO']))->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson('/admin/users', $this->newUser(['username' => 'con espacio']))->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson('/admin/users', $this->newUser(['password' => 'corta']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/admin/users', $this->newUser(['role' => 'root']))->assertStatus(422)->assertJsonValidationErrors('role');
        $this->postJson('/admin/users', $this->newUser(['username' => 'Mayus.Minus']))->assertCreated()->assertJsonPath('data.username', 'mayus.minus'); // stored lowercase
    }

    public function test_nobody_deactivates_themselves_or_drops_their_own_admin_role_and_one_admin_always_remains(): void
    {
        $admin = $this->person('admin');
        $this->actingAs($admin);

        $this->patchJson("/admin/users/{$admin->id}/deactivate")->assertStatus(422);
        $this->putJson("/admin/users/{$admin->id}", ['name' => $admin->name, 'username' => $admin->username, 'email' => $admin->email, 'role' => 'dispatch'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->postJson("/admin/users/{$admin->id}/reset-password")->assertStatus(422);

        // Two administrators: one may deactivate the other, never the last one. Only administrators reach these endpoints,
        // so the rule is also checked on the service with another kind of actor (a future role with users.manage).
        $second = $this->person('admin');
        $this->patchJson("/admin/users/{$second->id}/deactivate")->assertOk();
        $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());

        $actor = $this->person('dispatch');
        $service = app(UserService::class);
        $this->assertTrue($this->refuses(fn () => $service->deactivate($admin, $actor)));
        $this->assertTrue($this->refuses(fn () => $service->update($admin, ['name' => $admin->name, 'username' => $admin->username, 'email' => $admin->email, 'role' => 'dispatch'], $actor)));
        $this->assertSame([true, Role::Admin], [$admin->fresh()->is_active, $admin->fresh()->role]);
    }

    private function refuses(callable $action): bool
    {
        try {
            $action();
        } catch (ValidationException $error) {
            return str_contains(json_encode($error->errors(), JSON_UNESCAPED_UNICODE), 'al menos un administrador activo');
        }

        return false;
    }

    public function test_a_delivery_person_with_pending_deliveries_cannot_be_deactivated_until_they_are_reassigned(): void
    {
        $courier = $this->person('delivery');
        $other = $this->person('delivery');
        $order = $this->order('shipped', ['assigned_delivery_user_id' => $courier->id]);
        $this->actingAs($this->person('admin'));

        $this->patchJson("/admin/users/{$courier->id}/deactivate")->assertStatus(422)->assertJsonPath('errors.user.0', "Tiene entregas pendientes: {$order->reference}. Reasígnalas antes de continuar.");
        $this->putJson("/admin/users/{$courier->id}", ['name' => $courier->name, 'username' => $courier->username, 'email' => $courier->email, 'role' => 'dispatch'])->assertStatus(422);
        $this->patchJson("/admin/orders/{$order->id}/reassign", ['delivery_user_id' => $other->id])->assertOk();
        $this->patchJson("/admin/users/{$courier->id}/deactivate")->assertOk();
    }

    public function test_people_cannot_be_deleted(): void
    {
        $admin = $this->person('admin');
        $target = $this->person('dispatch');
        foreach (['/admin/users/'.$target->id, '/admin/users'] as $uri) {
            $this->assertContains($this->actingAs($admin)->deleteJson($uri)->status(), [404, 405]);
        }
        $this->assertNotNull(User::find($target->id));
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => str_starts_with($route->uri(), 'admin/users') && in_array('DELETE', $route->methods(), true)));
    }

    public function test_the_catalogue_of_the_historial_knows_the_users_module(): void
    {
        $this->actingAs($this->person('admin'));
        $json = $this->getJson('/admin/activity/data')->assertOk()->json('options');
        $this->assertContains('users', collect($json['modules'])->pluck('value')->all());
        $this->assertContains('password_reset', collect($json['actions']['users'])->pluck('value')->all());
        $this->assertSame(1, app(ActivityLogger::class)->log('users', 'created', null, [], [], 'x')->id);
    }

    public function test_a_legacy_non_admin_flag_gives_no_access(): void
    {
        $user = $this->person('delivery', ['is_admin' => false]);
        $this->actingAs($user)->getJson('/admin/users/data')->assertForbidden();
        $user->forceFill(['is_admin' => true])->save(); // the old column is ignored: only the role counts
        $this->getJson('/admin/users/data')->assertForbidden();
        $this->assertSame(1, DB::table('users')->count());
        $this->assertNull(Customer::first());
    }
}
