<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Support\SpaRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** HTTP status, head data and page list of every public and admin route. */
class RoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function product(string $slug = 'cafe-premium', string $sku = 'FH-001'): array
    {
        $category = Category::firstOrCreate(['slug' => 'cafe'], ['name' => 'Café', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Café Premium', 'slug' => $slug, 'is_published' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'name' => 'Bolsa 250 g', 'price_cop' => 34900, 'is_published' => true]);

        return [$product, $variant];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function title(string $html): string
    {
        preg_match('#<title>([^<]*)</title>#', $html, $found);

        return html_entity_decode($found[1] ?? '', ENT_QUOTES);
    }

    // ---- Public pages ----

    public function test_every_storefront_page_answers_200_with_its_own_title_and_description(): void
    {
        $this->product();
        $titles = [];

        foreach (['/', '/promociones', '/sello-de-origen', '/sello-de-origen.html', '/blog', '/blog.html', '/blog/tostion-media-baja', '/blog/tostion-media-baja.html', '/blog/cafe-en-prensa-francesa', '/producto/cafe-premium'] as $path) {
            $response = $this->get($path)->assertOk();
            $title = $this->title($response->getContent());
            $this->assertNotSame('', $title, "$path has no title");
            $titles[$path] = $title;
        }

        // Ten URLs, seven different pages: the .html aliases share the title of their page.
        $this->assertCount(7, array_unique($titles), 'every page needs its own title (the .html aliases share theirs)');
        $this->get('/sello-de-origen')->assertSee('<meta name="description"', false);
        $this->get('/blog')->assertSee('<meta name="description"', false);
        $this->get('/blog/tostion-media-baja')->assertSee('Tostión media-baja', false);
        $this->get('/')->assertSee('<meta name="description"', false)->assertDontSee('noindex', false);
    }

    public function test_promociones_answers_200_with_and_without_current_promotions(): void
    {
        $this->get('/promociones')->assertOk()->assertSee('noindex,nofollow', false)->assertDontSee('<meta name="description"', false);

        [$product, $variant] = $this->product();
        $promotion = Promotion::create(['product_id' => $product->id, 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        $promotion->variants()->sync([$variant->id]);

        $this->get('/promociones')->assertOk()->assertDontSee('noindex', false)->assertSee('<meta name="description"', false)->assertSee('Promociones | Café Finca Helena', false);
    }

    public function test_product_pages_answer_200_301_and_404(): void
    {
        $this->product('cafe-premium', 'FH-001');

        $this->get('/producto/cafe-premium')->assertOk()->assertSee('Café Premium | Café Finca Helena', false);
        $this->get('/producto/fh-001')->assertStatus(301)->assertRedirect('/producto/cafe-premium');
        $this->get('/producto/no-existe')->assertNotFound();
    }

    public function test_unknown_pages_answer_a_real_404(): void
    {
        $this->get('/ruta-inventada')->assertNotFound()->assertSee('noindex', false)->assertSee('Página no encontrada', false);
        $this->get('/blog/no-existe')->assertNotFound();
        $this->get('/blog/no-existe.html')->assertNotFound();
        $this->get('/sello-de-origen/extra')->assertNotFound();
        $this->get('/api/no-existe')->assertNotFound();
    }

    public function test_sitemap_and_api_still_answer(): void
    {
        $this->get('/sitemap.xml')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/categories')->assertOk();
    }

    // ---- Admin ----

    public function test_admin_pages_redirect_a_guest_to_the_login(): void
    {
        foreach (SpaRoutes::adminPages() as $page) {
            $path = preg_replace('/\{\w+\}/', '1', $page['path']);
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->get('/admin/login')->assertOk()->assertSee('noindex', false)->assertSee('Acceso administrativo', false);
    }

    public function test_admin_pages_answer_200_with_their_own_title_to_an_administrator(): void
    {
        $admin = $this->admin();
        $titles = [];

        foreach (SpaRoutes::adminPages() as $page) {
            $path = preg_replace('/\{\w+\}/', '1', $page['path']);
            $response = $this->actingAs($admin)->get($path)->assertOk()->assertSee('noindex', false);
            $titles[] = $this->title($response->getContent());
            $this->assertStringContainsString($page['title'], end($titles));
        }

        $this->assertCount(count(SpaRoutes::adminPages()), array_unique($titles));
        // A logged-in administrator does not need the login page.
        $this->actingAs($admin)->get('/admin/login')->assertRedirect('/admin/today');
    }

    public function test_admin_pages_refuse_other_users_and_unknown_paths(): void
    {
        // A "Solo entrega" person reaches only the pages whose permission the role has (or that need none).
        $courier = User::factory()->role('delivery')->create();
        foreach (SpaRoutes::adminPages() as $page) {
            $allowed = empty($page['permission']) || $courier->hasPermission($page['permission']);
            $response = $this->actingAs($courier)->get(preg_replace('/\{\w+\}/', '1', $page['path']));
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }

        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/orders/abc')->assertNotFound();
        $this->actingAs($admin)->get('/admin/no-existe')->assertNotFound();
        $this->get('/admin/no-existe')->assertNotFound();
    }

    // ---- The shared page list (routes/spa.json) ----

    public function test_every_listed_storefront_route_resolves_and_has_a_known_layout(): void
    {
        $this->product('cafe-premium');
        foreach (SpaRoutes::storefront() as $route) {
            $this->assertContains($route['layout'], ['shop', 'origin', 'blog', 'article']);
            $path = str_replace('{slug}', $route['page'] === 'article' ? 'tostion-media-baja' : 'cafe-premium', $route['path']);
            $this->get($path)->assertOk();
        }
    }

    public function test_the_article_list_matches_the_articles_the_storefront_renders(): void
    {
        preg_match_all("/slug:'([^']+)'/", file_get_contents(resource_path('js/modules/content/articles.js')), $found);

        $this->assertSame($found[1], array_column(SpaRoutes::config()['articles'], 'slug'), 'routes/spa.json "articles" must list the slugs of resources/js/modules/content/articles.js');
    }
}
