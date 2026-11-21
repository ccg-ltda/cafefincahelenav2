<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordLinkTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $admin, User $target): string
    {
        $response = $this->actingAs($admin)->postJson("/admin/users/{$target->id}/password-link")->assertOk();

        return substr(strstr($response->json('link'), '#'), 1);
    }

    public function test_an_admin_creates_a_link_shown_once_and_only_the_hash_is_stored(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->role('dispatch')->create(['must_change_password' => false]);

        $response = $this->actingAs($admin)->postJson("/admin/users/{$target->id}/password-link")->assertOk();
        $token = substr(strstr($response->json('link'), '#'), 1);

        $this->assertStringContainsString('/admin/recover-password#', $response->json('link'));
        $this->assertSame(24, $response->json('valid_hours'));
        $this->assertSame(hash('sha256', $token), UserPasswordLink::sole()->token_hash);
        $this->assertDatabaseMissing('user_password_links', ['token_hash' => $token]);
        $log = ActivityLog::where('action', 'password_link_created')->sole();
        $this->assertStringNotContainsString($token, json_encode($log->toArray()));
    }

    public function test_the_person_sets_a_password_with_the_link_once(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->role('dispatch')->create(['must_change_password' => true]);
        $version = $target->session_version;
        $token = $this->tokenFor($admin, $target);

        $this->postJson('/admin/password-link/check', ['token' => $token])->assertOk()->assertJson(['valid' => true]);
        $this->postJson('/admin/password-link', ['token' => $token, 'password' => 'Nueva-clave-2026', 'password_confirmation' => 'Nueva-clave-2026'])->assertOk();

        $target->refresh();
        $this->assertTrue(Hash::check('Nueva-clave-2026', $target->password));
        $this->assertFalse($target->must_change_password);
        $this->assertGreaterThan($version, $target->session_version);
        $this->postJson('/admin/password-link', ['token' => $token, 'password' => 'Otra-clave-2026', 'password_confirmation' => 'Otra-clave-2026'])->assertStatus(422)->assertJsonValidationErrors('token');
        $this->postJson('/admin/password-link/check', ['token' => $token])->assertStatus(422);
    }

    public function test_an_expired_or_replaced_link_does_not_work(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->role('delivery')->create();
        $first = $this->tokenFor($admin, $target);
        $second = $this->tokenFor($admin, $target); // replaces the first

        $this->postJson('/admin/password-link/check', ['token' => $first])->assertStatus(422);
        $this->travel(25)->hours();
        $this->postJson('/admin/password-link/check', ['token' => $second])->assertStatus(422);
        $this->postJson('/admin/password-link', ['token' => $second, 'password' => 'Nueva-clave-2026', 'password_confirmation' => 'Nueva-clave-2026'])->assertStatus(422);
    }

    public function test_rules_and_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->role('dispatch')->create();
        $inactive = User::factory()->role('delivery')->create(['is_active' => false]);

        $this->actingAs($admin)->postJson("/admin/users/{$admin->id}/password-link")->assertStatus(422); // not your own
        $this->actingAs($admin)->postJson("/admin/users/{$inactive->id}/password-link")->assertStatus(422); // reactivate first
        $this->actingAs($other)->postJson("/admin/users/{$inactive->id}/password-link")->assertForbidden();
        auth()->logout();
        $this->postJson("/admin/users/{$other->id}/password-link")->assertUnauthorized();
        $this->postJson('/admin/password-link', ['token' => 'x', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->get('/admin/recover-password')->assertOk(); // the page is public
    }
}
