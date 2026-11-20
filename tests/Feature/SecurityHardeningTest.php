<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Security audit, phase 2: headers/CSP, login locks, password policy, CORS, 422/404 instead of 500, error pages, expected_status. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.vite_hot_file' => sys_get_temp_dir().'/cfh-no-vite-dev-server']); // the developer's own public/hot must not change these tests
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes + ['username' => 'sec.admin', 'password' => 'Clave-Segura-Zx9']);
    }

    private function login(string $login, string $password = 'mala-clave-1')
    {
        return $this->postJson('/admin/login', ['login' => $login, 'password' => $password]);
    }

    // ---- A1: headers

    public function test_every_answer_carries_the_security_headers_and_no_powered_by(): void
    {
        foreach (['/', '/admin/login', '/api/products', '/no-existe-esta-pagina'] as $path) {
            $response = $this->get($path);
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp, $path);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp, $path);
            $this->assertStringContainsString("object-src 'none'", $csp, $path);
            $this->assertStringContainsString("base-uri 'self'", $csp, $path);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
            $this->assertFalse($response->headers->has('X-Powered-By'), $path);
            $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'HSTS only in production over HTTPS');
        }
    }

    public function test_the_policy_allows_what_the_store_needs_and_nothing_else(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);
        $this->assertStringContainsString('https://fonts.gstatic.com', $csp);
        $this->assertMatchesRegularExpression("/img-src [^;]*blob:/", $csp); // the image picker preview
        $this->assertMatchesRegularExpression("/img-src [^;]*data:/", $csp);
        $this->assertMatchesRegularExpression("/connect-src 'self'(?!.*https:)/", $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src [^;]*'unsafe-(inline|eval)'/", $csp); // outside `npm run dev`
        $this->assertStringNotContainsString('*', $csp);
    }

    public function test_while_the_vite_dev_server_runs_the_policy_allows_it_but_never_in_production(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        config(['security.vite_hot_file' => $hot]);
        try {
            // A named host: its own origin, plus the websocket of hot reload.
            file_put_contents($hot, "http://localhost:5173\n");
            $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src [^;]*http:\/\/localhost:5173/", $csp);
            $this->assertStringContainsString('ws://localhost:5173', $csp);

            // Vite writes an IPv6 address (`http://[::1]:5173`) that CSP cannot name: the dev server is allowed by scheme.
            file_put_contents($hot, "http://[::1]:5173\n");
            $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src [^;]*\shttp:(\s|;|$)/", $csp);
            $this->assertMatchesRegularExpression("/connect-src [^;]*\sws:(\s|;|$)/", $csp);
            $this->assertStringNotContainsString('[::1]', $csp);

            $this->app->detectEnvironment(fn () => 'production');
            $production = (string) $this->get('https://localhost/')->headers->get('Content-Security-Policy');
            $this->assertStringNotContainsString('5173', $production);
            $this->assertDoesNotMatchRegularExpression("/script-src [^;]*\shttp:(\s|;|$)/", $production);
        } finally {
            @unlink($hot);
        }
    }

    public function test_the_inline_script_of_the_email_preview_runs_only_with_the_nonce_of_that_response(): void
    {
        $this->actingAs($this->admin());
        $response = $this->get('/admin/emails/preview');
        preg_match("/script-src [^;]*'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $header);
        preg_match('/<script nonce="([^"]+)"/', $response->getContent(), $page);
        $this->assertNotEmpty($header[1] ?? null);
        $this->assertSame($header[1], $page[1] ?? null);
        $other = $this->get('/admin/emails/preview');
        preg_match("/'nonce-([^']+)'/", (string) $other->headers->get('Content-Security-Policy'), $second);
        $this->assertNotSame($header[1], $second[1], 'a new nonce for every response');
    }

    public function test_report_only_mode_reports_without_blocking(): void
    {
        config(['security.csp_report_only' => true]);
        $response = $this->get('/');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_production_over_https_adds_hsts_and_upgrades_insecure_requests(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $secure = $this->get('https://localhost/');
        $this->assertStringContainsString('max-age=31536000', (string) $secure->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('upgrade-insecure-requests', (string) $secure->headers->get('Content-Security-Policy'));
        $this->assertFalse($this->get('http://localhost/')->headers->has('Strict-Transport-Security'), 'never over plain HTTP');
    }

    // ---- M2: trusted proxies

    public function test_trusted_proxies_are_empty_by_default_and_star_only_when_written(): void
    {
        $this->assertNull(TrustedProxies::parse(null));
        $this->assertNull(TrustedProxies::parse(''));
        $this->assertNull(TrustedProxies::parse(' , ,'));
        $this->assertSame('*', TrustedProxies::parse('*'));
        $this->assertSame(['10.0.0.1', '173.245.48.0/20'], TrustedProxies::parse('10.0.0.1, 173.245.48.0/20'));
        $this->assertSame(['10.0.0.1'], TrustedProxies::parse('10.0.0.1,*'), 'a star inside a list is dropped, never promoted to "trust everyone"');
    }

    public function test_without_trusted_proxies_a_spoofed_forwarded_header_is_ignored(): void
    {
        Route::get('/__ip', fn (Request $request) => $request->ip());
        $this->withHeader('X-Forwarded-For', '9.9.9.9')->get('/__ip')->assertSee('127.0.0.1', false);
    }

    // ---- A2 / M7 / M1: login

    public function test_an_account_is_locked_progressively_whatever_the_ip(): void
    {
        $this->admin();
        foreach (range(1, 5) as $i) {
            $this->login('sec.admin')->assertStatus(422)->assertJsonPath('errors.login.0', 'Las credenciales no son válidas.');
        }
        // 5 failures: 1 minute. Even the right password waits.
        $this->login('sec.admin', 'Clave-Segura-Zx9')->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->login('sec.admin')->assertStatus(422); // 6th failure: now 5 minutes
        $this->login('sec.admin', 'Clave-Segura-Zx9')->assertStatus(429);
        $this->travel(299)->seconds();
        $this->login('sec.admin', 'Clave-Segura-Zx9')->assertStatus(429);
        $this->travel(2)->seconds();
        $this->login('sec.admin')->assertStatus(422); // 7th failure: 15 minutes
        $this->travel(899)->seconds();
        $this->login('sec.admin', 'Clave-Segura-Zx9')->assertStatus(429);
        $this->travel(2)->seconds();
        $this->login('sec.admin', 'Clave-Segura-Zx9')->assertOk();
        $this->post('/admin/logout');
        $this->login('sec.admin')->assertStatus(422); // a success cleared the counter
    }

    public function test_a_locked_account_does_not_lock_the_others_nor_the_whole_ip(): void
    {
        $this->admin();
        User::factory()->role('dispatch')->create(['username' => 'otra.persona', 'password' => 'Clave-Segura-Zx9']);
        foreach (range(1, 6) as $i) {
            $this->login('sec.admin');
        }
        $this->login('sec.admin', 'Clave-Segura-Zx9')->assertStatus(429);
        $this->login('otra.persona', 'Clave-Segura-Zx9')->assertOk();
    }

    public function test_the_ip_limit_is_generous_but_it_exists(): void
    {
        foreach (range(1, 60) as $i) {
            $this->login("no.existe.$i")->assertStatus(422);
        }
        $this->login('no.existe.61')->assertStatus(429);
    }

    public function test_the_lock_is_written_in_the_historial_once_and_without_the_password(): void
    {
        $this->admin();
        foreach (range(1, 5) as $i) {
            $this->login('sec.admin', 'secreta-que-no-va-al-historial');
        }
        $this->login('sec.admin', 'secreta-que-no-va-al-historial'); // locked: counts nothing, logs nothing
        $rows = ActivityLog::where('action', 'account_locked')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['login' => 's***', 'attempts' => 5, 'minutes' => 1], $rows[0]->context);
        $this->assertStringNotContainsString('secreta-que-no-va-al-historial', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_an_unknown_name_is_throttled_the_same_way_without_leaving_rows(): void
    {
        foreach (range(1, 5) as $i) {
            $this->login('nadie.existe')->assertStatus(422)->assertJsonPath('errors.login.0', 'Las credenciales no son válidas.');
        }
        $this->login('nadie.existe')->assertStatus(429); // the same answer a real account gets
        $this->assertSame(0, ActivityLog::where('action', 'account_locked')->count(), 'random names cannot fill the Historial');
    }

    public function test_an_unknown_user_is_still_checked_against_a_hash(): void
    {
        $counter = new class($this->app) extends \Illuminate\Hashing\HashManager
        {
            public int $checks = 0;

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        $this->app->instance('hash', $counter);
        Hash::clearResolvedInstance('hash');

        $this->login('no.existe.nunca')->assertStatus(422);
        $this->assertSame(1, $counter->checks, 'a name that does not exist still costs one password check');
        $this->admin();
        $this->login('sec.admin')->assertStatus(422);
        $this->assertSame(2, $counter->checks);
    }

    // ---- M4: passwords

    /** @return array<string, array{0: string}> */
    public static function weakPasswords(): array
    {
        return ['repeated' => ['aaaaaaaaaaaa'], 'two characters' => ['abababababab'], 'ascending digits' => ['Zq-123456789'], 'ascending letters' => ['Abcdefgh-1X'], 'descending' => ['Zq-987654321'], 'common word' => ['Mi-password-Zq7'], 'brand' => ['FincaHelena-77x']];
    }

    /** @dataProvider weakPasswords */
    public function test_obvious_passwords_are_refused(string $password): void
    {
        $this->actingAs($this->admin());
        $this->postJson('/admin/users', ['name' => 'Nueva Persona', 'username' => 'nueva.persona', 'role' => 'dispatch', 'password' => $password])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertNull(User::where('username', 'nueva.persona')->first());
    }

    public function test_a_good_password_is_accepted_and_a_leaked_one_is_refused(): void
    {
        config(['security.check_pwned_passwords' => true]);
        $this->actingAs($this->admin());
        $good = 'Tz4-Vq8-Mn2-Lk';
        $hash = strtoupper(sha1($good));
        $body = "0000000000000000000000000000000000A:3\n".substr($hash, 5).':1200';
        Http::fake(['api.pwnedpasswords.com/*' => function () use (&$body) {
            return Http::response($body, 200);
        }]);
        $this->postJson('/admin/users', ['name' => 'Persona Uno', 'username' => 'persona.uno', 'role' => 'dispatch', 'password' => $good])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Esa contraseña apareció en filtraciones de datos conocidas. Elige otra.');

        $body = '0000000000000000000000000000000000A:3';
        $this->postJson('/admin/users', ['name' => 'Persona Uno', 'username' => 'persona.uno', 'role' => 'dispatch', 'password' => $good])->assertCreated();
    }

    public function test_a_leak_service_that_does_not_answer_never_blocks_saving(): void
    {
        config(['security.check_pwned_passwords' => true]);
        $this->actingAs($this->admin());
        $mode = 'timeout';
        Http::fake(['api.pwnedpasswords.com/*' => function () use (&$mode) {
            return $mode === 'timeout' ? throw new \Illuminate\Http\Client\ConnectionException('timeout') : Http::response('boom', 500);
        }]);
        $this->postJson('/admin/users', ['name' => 'Persona Dos', 'username' => 'persona.dos', 'role' => 'dispatch', 'password' => 'Tz4-Vq8-Mn2-Lk'])->assertCreated();
        $mode = 'error';
        $this->postJson('/admin/users', ['name' => 'Persona Tres', 'username' => 'persona.tres', 'role' => 'dispatch', 'password' => 'Tz4-Vq8-Mn2-Lk'])->assertCreated();
    }

    public function test_the_own_password_change_and_the_admin_reset_use_the_same_policy(): void
    {
        $user = User::factory()->role('dispatch')->create(['must_change_password' => true, 'password' => 'Temporal-Qx7Lm']);
        $this->actingAs($user)->postJson('/admin/password', ['password' => 'aaaaaaaaaaaa', 'password_confirmation' => 'aaaaaaaaaaaa'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($user)->postJson('/admin/password', ['password' => 'Tz4-Vq8-Mn2-Lk', 'password_confirmation' => 'Tz4-Vq8-Mn2-Lk'])->assertOk();

        $this->flushSession();
        $this->actingAs($this->admin());
        $this->postJson("/admin/users/{$user->id}/reset-password", ['password' => 'Zq-123456789'])->assertStatus(422);
        $this->postJson("/admin/users/{$user->id}/reset-password", [])->assertOk(); // generated ones need no check
    }

    // ---- M5: CORS

    public function test_only_the_store_origin_may_call_the_api_from_a_browser(): void
    {
        $own = rtrim((string) config('cors.allowed_origins.0'), '/');
        $parts = parse_url((string) config('app.url'));
        $this->assertSame($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''), $own, 'APP_URL is the only allowed origin');
        // The only origin ever announced is the store's own, so a browser refuses https://evil.example (never "*", never an echo of its origin).
        foreach ([$this->withHeaders(['Origin' => 'https://evil.example'])->get('/api/products'), $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])->call('OPTIONS', '/api/orders')] as $response) {
            $this->assertNotContains($response->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);
        }
        $this->withHeaders(['Origin' => $own])->get('/api/products')->assertHeader('Access-Control-Allow-Origin', $own);
        $this->assertFalse((bool) config('cors.supports_credentials'));
    }

    // ---- M6: 422 / 404 instead of 500

    public function test_odd_values_answer_422_or_404_never_500(): void
    {
        $this->actingAs($this->admin());
        $huge = '99999999999999999999';
        foreach (["/admin/orders/$huge/detail", "/admin/customers/$huge/detail", "/admin/users/$huge/detail", "/admin/products/$huge/detail", "/admin/coupons/$huge", "/admin/promotions/$huge"] as $url) {
            $this->getJson($url)->assertNotFound();
        }
        $this->getJson("/admin/users/username-check?username=abc&ignore=$huge")->assertStatus(422);
        $this->patchJson('/admin/orders/1/ship', ['delivery_user_id' => $huge, 'expected_status' => 'confirmed'])->assertStatus(422);
        $this->patchJson('/admin/orders/1/reassign', ['delivery_user_id' => $huge])->assertStatus(422);
        $this->postJson('/api/orders/quote', ['items' => [['variant_id' => $huge, 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/orders/pending/status', ['reference' => ['a'], 'customer_email' => 'a@b.co'])->assertStatus(422);
        $this->postJson('/api/orders/pending/cancel', ['reference' => 'CFH-AAAAAAAAAAAA', 'customer_email' => ['x']])->assertStatus(422);
    }

    // ---- B3: ids are digits

    public function test_every_admin_route_with_an_id_only_accepts_digits(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin/')) {
                continue;
            }
            foreach ($route->parameterNames() as $name) {
                if (in_array($name, ['type', 'path'], true)) {
                    continue;
                }
                $this->assertArrayHasKey($name, $route->wheres, $route->uri()." {{$name}}");
                $checked++;
            }
        }
        $this->assertGreaterThan(40, $checked);
    }

    // ---- B4: expected_status

    public function test_every_order_step_requires_the_status_the_person_was_looking_at(): void
    {
        $this->actingAs($this->admin());
        foreach (['prepare', 'ready', 'ship', 'deliver'] as $step) {
            $this->patchJson("/admin/orders/1/$step", ['carrier' => 'X'])->assertStatus(422)->assertJsonValidationErrors('expected_status');
        }
        $this->assertStringContainsString('expected_status: order.status', file_get_contents(resource_path('js/modules/admin/orderActions.jsx')), 'Hoy and Pedidos send it from the one shared implementation');
    }

    // ---- B2: error pages

    public function test_the_error_pages_are_in_spanish_with_the_brand_and_no_technical_data(): void
    {
        config(['app.debug' => false]);
        Route::get('/__error/{code}', function (int $code) {
            if ($code === 500) {
                throw new \RuntimeException('detalle-interno-secreto '.base_path());
            }
            abort($code, 'detalle-interno-secreto');
        });
        $expected = [403 => 'No tienes acceso', 404 => 'No encontramos esa página', 419 => 'La página caducó', 429 => 'Demasiados intentos seguidos', 500 => 'Algo salió mal', 503 => 'Estamos haciendo mejoras'];
        foreach ($expected as $code => $heading) {
            $response = $this->withHeader('Accept', 'text/html')->get("/__error/$code");
            $response->assertStatus($code)->assertSee('Café Finca Helena')->assertSee($heading)->assertSee('Volver al inicio');
            $response->assertDontSee('detalle-interno-secreto')->assertDontSee('Illuminate')->assertDontSee('RuntimeException')->assertDontSee(base_path());
        }
        $this->getJson('/__error/404')->assertStatus(404)->assertJsonStructure(['message']); // JSON clients keep JSON
    }

    // ---- M3 / docs

    public function test_the_example_env_and_the_deploy_guide_carry_the_production_settings(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString("SESSION_ENCRYPT=true\n", str_replace("\r\n", "\n", $env));
        foreach (['SECURITY_CSP_REPORT_ONLY=false', 'TRUSTED_PROXIES=', 'SECURITY_CHECK_PWNED=true'] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        $deploy = file_get_contents(base_path('DEPLOY.md'));
        foreach (['SECURITY_CSP_REPORT_ONLY', 'TRUSTED_PROXIES', 'SESSION_ENCRYPT', 'expose_php', 'LOG_STACK=daily', 'LOG_LEVEL=warning', 'Strict-Transport-Security', 'autoindex off', 'Options -Indexes'] as $needle) {
            $this->assertStringContainsString($needle, $deploy, $needle);
        }
    }
}
