<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsappMenuClick;
use App\Models\WhatsappQuickReply;
use App\Models\WhatsappRequest;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $name = 'Ana Admin'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function form(array $override = []): array
    {
        return $override + ['name' => 'Cafetería El Roble', 'phone' => '300 123 4567', 'topic' => 'wholesale', 'message' => 'Quiero 20 kg de café en grano para mi cafetería.'];
    }

    private function request(array $attributes = []): WhatsappRequest
    {
        return WhatsappRequest::create($attributes + ['name' => 'Cliente', 'phone' => '3001234567', 'topic' => 'wholesale', 'message' => 'Hola', 'status' => 'new']);
    }

    // ---- Public click ----

    public function test_a_valid_click_is_stored_with_only_topic_path_and_time(): void
    {
        $this->postJson('/api/whatsapp/clicks', ['topic' => 'wholesale', 'page_path' => '/producto/cafe-premium?utm=x&email=a@b.co#top'])->assertNoContent();

        $click = WhatsappMenuClick::firstOrFail();
        $this->assertSame(['wholesale', '/producto/cafe-premium'], [$click->topic, $click->page_path]);
        $this->assertSame(['id', 'topic', 'page_path', 'created_at'], Schema::getColumnListing('whatsapp_menu_clicks'));
    }

    public function test_invalid_clicks_are_refused(): void
    {
        $this->postJson('/api/whatsapp/clicks', ['topic' => 'inventado', 'page_path' => '/'])->assertStatus(422);
        $this->postJson('/api/whatsapp/clicks', ['topic' => 'wholesale', 'page_path' => 'sin-barra'])->assertStatus(422);
        $this->postJson('/api/whatsapp/clicks', ['topic' => 'wholesale', 'page_path' => '/<script>'])->assertStatus(422);
        $this->postJson('/api/whatsapp/clicks', ['topic' => 'wholesale'])->assertStatus(422);
        $this->assertSame(0, WhatsappMenuClick::count());
    }

    public function test_clicks_are_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/whatsapp/clicks', ['topic' => 'buying_help', 'page_path' => '/'])->assertNoContent();
        }
        $this->postJson('/api/whatsapp/clicks', ['topic' => 'buying_help', 'page_path' => '/'])->assertStatus(429);
    }

    public function test_the_summary_counts_clicks_of_the_last_30_days_by_topic(): void
    {
        foreach ([['wholesale', 1], ['wholesale', 10], ['order_support', 29], ['buying_help', 31], ['wholesale', 45]] as [$topic, $daysAgo]) {
            WhatsappMenuClick::create(['topic' => $topic, 'page_path' => '/'])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }
        $this->request(['status' => 'new']);
        $this->request(['status' => 'in_progress']);
        $this->request(['status' => 'resolved', 'resolved_at' => now()->subDays(5)]);
        $this->request(['status' => 'resolved', 'resolved_at' => now()->subDays(40)]);

        $summary = $this->actingAs($this->admin())->getJson('/admin/whatsapp/data')->assertOk()->json('summary');

        $this->assertSame(['new' => 1, 'in_progress' => 1, 'resolved_30d' => 1, 'clicks_30d' => 3, 'clicks_by_topic' => ['wholesale' => 2, 'order_support' => 1, 'buying_help' => 0]], $summary);
    }

    // ---- Registering requests ----

    public function test_a_request_is_registered_with_a_normalized_phone(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['phone' => '+57 300-123-4567']))->assertCreated()
            ->assertJsonPath('data.phone', '3001234567')->assertJsonPath('data.status', 'new')->assertJsonPath('data.assignee', null);
        $this->assertSame($admin->id, WhatsappRequest::firstOrFail()->created_by);
    }

    public function test_an_invalid_phone_topic_or_empty_message_gives_422(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['phone' => '123']))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['phone' => '2001234567']))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['topic' => 'otro']))->assertStatus(422)->assertJsonValidationErrors('topic');
        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['message' => '']))->assertStatus(422)->assertJsonValidationErrors('message');
        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['order_reference' => 'ABC']))->assertStatus(422)->assertJsonValidationErrors('order_reference');
        $this->assertSame(0, WhatsappRequest::count());
        $this->assertSame('3001234567', Phone::normalize(' (300) 123-4567 '));
    }

    public function test_the_request_links_to_the_customer_with_the_same_phone(): void
    {
        $customer = Customer::create(['email' => 'ana@example.com', 'name' => 'Ana', 'phone' => '3001234567', 'city' => 'Bogotá']);
        Order::create(['customer_id' => $customer->id, 'reference' => 'CFH-AAAAAAAAAAAA', 'customer_name' => 'Ana', 'customer_email' => 'ana@example.com', 'customer_phone' => '3001234567', 'delivery_address' => 'x', 'delivery_city' => 'Bogotá', 'subtotal_cop' => 1000, 'total_cop' => 1000, 'currency' => 'COP', 'terms_accepted_at' => now(), 'data_consent_at' => now()]);
        $admin = $this->admin();

        $linked = $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form())->assertCreated();
        $linked->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.customer.linkable', true);
        $this->assertSame($customer->id, WhatsappRequest::firstOrFail()->customer_id);

        $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['phone' => '3109998888']))->assertCreated()->assertJsonPath('data.customer', null);
    }

    public function test_the_order_reference_comes_from_the_field_or_from_the_text_and_the_link_needs_the_order_to_exist(): void
    {
        Order::create(['reference' => 'CFH-746BF159B397', 'customer_name' => 'Ana', 'customer_email' => 'ana@example.com', 'customer_phone' => '3001234567', 'delivery_address' => 'x', 'delivery_city' => 'Bogotá', 'subtotal_cop' => 1000, 'total_cop' => 1000, 'currency' => 'COP', 'terms_accepted_at' => now(), 'data_consent_at' => now()]);
        $admin = $this->admin();

        $fromText = $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['topic' => 'order_support', 'message' => 'Mi pedido cfh-746bf159b397 no llega']))->assertCreated();
        $fromText->assertJsonPath('data.order_reference', 'CFH-746BF159B397')->assertJsonPath('data.order.reference', 'CFH-746BF159B397');

        $typed = $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['order_reference' => 'cfh-000000000000', 'message' => 'Revisen CFH-746BF159B397']))->assertCreated();
        $typed->assertJsonPath('data.order_reference', 'CFH-000000000000')->assertJsonPath('data.order', null); // exists only as text: "(no encontrado)"

        $none = $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form())->assertCreated();
        $none->assertJsonPath('data.order_reference', null);
    }

    // ---- Transitions ----

    public function test_take_resolve_and_reopen(): void
    {
        $admin = $this->admin();
        $request = $this->request();

        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/take")->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.assignee.id', $admin->id)->assertJsonPath('summary.in_progress', 1);

        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/resolve", ['note' => 'Se envió la cotización'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution_note', 'Se envió la cotización');
        $this->assertNotNull($request->fresh()->resolved_at);

        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/take")->assertStatus(422); // reopen first

        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/reopen")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNull($request->fresh()->resolved_at);

        $unassigned = $this->request(['status' => 'resolved', 'resolved_at' => now()]);
        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$unassigned->id}/reopen")->assertOk()->assertJsonPath('data.status', 'new');
    }

    public function test_assign_only_to_an_administrator_and_save_notes(): void
    {
        $admin = $this->admin();
        $other = $this->admin('Beto Admin');
        $customer = User::factory()->create();
        $request = $this->request();

        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/assign", ['user_id' => $other->id])->assertOk()
            ->assertJsonPath('data.assignee.name', 'Beto Admin')->assertJsonPath('data.status', 'in_progress');
        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/assign", ['user_id' => $customer->id])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/admin/whatsapp/requests/{$request->id}/notes", ['internal_notes' => "Llamar mañana 📞\nPidió precio por kilo"])->assertOk();
        $this->assertSame("Llamar mañana 📞\nPidió precio por kilo", $request->fresh()->internal_notes);
    }

    public function test_the_list_filters_by_open_topic_and_all(): void
    {
        $this->request(['topic' => 'wholesale', 'status' => 'new']);
        $this->request(['topic' => 'order_support', 'status' => 'in_progress']);
        $this->request(['topic' => 'order_support', 'status' => 'resolved', 'resolved_at' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/whatsapp/data')->assertJsonPath('meta.total', 2); // open by default
        $this->actingAs($admin)->getJson('/admin/whatsapp/data?filter=order_support')->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson('/admin/whatsapp/data?filter=all')->assertJsonPath('meta.total', 3);
        $this->actingAs($admin)->getJson('/admin/whatsapp/data?filter=otro')->assertStatus(422);
    }

    // ---- Quick replies and emojis ----

    public function test_the_three_quick_replies_are_seeded_by_the_migration_with_their_emojis(): void
    {
        $replies = WhatsappQuickReply::orderBy('sort_order')->get();

        $this->assertSame(['wholesale', 'order_support', 'buying_help'], $replies->pluck('topic')->all());
        $this->assertStringStartsWith('¡Hola! 👋 Gracias por escribir a Café Finca Helena.', $replies[0]->body);
        $this->assertStringContainsString('(CFH-…)', $replies[1]->body);
        $this->assertStringContainsString('¿Cómo preparas tu café', $replies[2]->body);
    }

    public function test_quick_replies_are_editable_and_emojis_survive(): void
    {
        $reply = WhatsappQuickReply::where('topic', 'wholesale')->firstOrFail();
        $text = "¡Hola! 👋☕ ¿Qué tal? Tenemos café ñandú 🇨🇴\nSegunda línea con acentos: árbol, Niño";

        $this->actingAs($this->admin())->putJson("/admin/whatsapp/quick-replies/{$reply->id}", ['title' => 'Mayoristas ☕', 'body' => $text])->assertOk();

        $this->assertSame($text, $reply->fresh()->body);
        $this->assertSame('Mayoristas ☕', $reply->fresh()->title);
        $this->actingAs($this->admin('Otra'))->getJson('/admin/whatsapp/data')->assertJsonPath('quick_replies.0.body', $text);
        $this->actingAs($this->admin('Otra más'))->putJson("/admin/whatsapp/quick-replies/{$reply->id}", ['title' => '', 'body' => 'x'])->assertStatus(422);
    }

    public function test_emojis_in_requests_are_stored_and_read_intact(): void
    {
        $admin = $this->admin();
        $created = $this->actingAs($admin)->postJson('/admin/whatsapp/requests', $this->form(['name' => 'Café Ñandú ☕', 'message' => 'Hola 👋 ¿Tienen café molido? 😀 Gracias, ¡saludos!']))->assertCreated()->json('data');

        $detail = $this->actingAs($admin)->getJson("/admin/whatsapp/requests/{$created['id']}")->assertOk()->json('data');
        $this->assertSame('Café Ñandú ☕', $detail['name']);
        $this->assertSame('Hola 👋 ¿Tienen café molido? 😀 Gracias, ¡saludos!', $detail['message']);
        $this->assertStringNotContainsString("\u{FFFD}", json_encode($detail, JSON_UNESCAPED_UNICODE));
    }

    // ---- Access ----

    public function test_only_an_administrator_reaches_the_admin_endpoints(): void
    {
        $request = $this->request();
        $reply = WhatsappQuickReply::firstOrFail();
        $user = User::factory()->create();
        $endpoints = [['get', '/admin/whatsapp/data'], ['post', '/admin/whatsapp/requests'], ['get', "/admin/whatsapp/requests/{$request->id}"], ['patch', "/admin/whatsapp/requests/{$request->id}/take"],
            ['patch', "/admin/whatsapp/requests/{$request->id}/assign"], ['patch', "/admin/whatsapp/requests/{$request->id}/resolve"], ['patch', "/admin/whatsapp/requests/{$request->id}/reopen"],
            ['patch', "/admin/whatsapp/requests/{$request->id}/notes"], ['put', "/admin/whatsapp/quick-replies/{$reply->id}"]];

        foreach ($endpoints as [$method, $url]) {
            $this->json($method, $url, $this->form())->assertUnauthorized();
        }
        foreach ($endpoints as [$method, $url]) {
            $this->actingAs($user)->json($method, $url, $this->form())->assertForbidden();
        }
        $this->assertSame('new', $request->fresh()->status);
    }

    public function test_the_shell_publishes_the_store_number_from_the_config(): void
    {
        $this->withoutVite();
        config(['shop.whatsapp_number' => '573027880823']);

        $this->get('/')->assertSee('<meta name="shop-whatsapp" content="573027880823">', false);
    }
}
