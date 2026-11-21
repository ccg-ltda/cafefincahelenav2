<?php

namespace App\Support;

/**
 * Reader of routes/spa.json, the single list of the pages the React app renders. The same file feeds
 * resources/js/routes.js (router, layout and page titles), so PHP and JS cannot drift apart:
 * a page is added or renamed in one place.
 */
final class SpaRoutes
{
    private static ?array $config = null;

    public static function config(): array
    {
        return self::$config ??= json_decode(file_get_contents(base_path('routes/spa.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function site(): string
    {
        return self::config()['site'];
    }

    /** @return array{path: string, title: string} */
    public static function adminLogin(): array
    {
        return self::config()['admin']['login'];
    }

    /** @return list<array{path: string, title: string}> */
    public static function adminPages(): array
    {
        return self::config()['admin']['pages'];
    }

    /** @return list<array{path: string, title: string}> Pages without sign-in whose link is the credential (no permission). */
    public static function adminPublicPages(): array
    {
        return self::config()['admin']['public'] ?? [];
    }

    /** Head data of an admin page (never indexed). */
    public static function adminSeo(array $page): array
    {
        return ['title' => $page['title'].' | Administración · '.self::site(), 'noindex' => true];
    }

    /** @return list<array<string, string>> */
    public static function storefront(): array
    {
        return self::config()['storefront'];
    }

    /** @return array{route: array<string, string>, params: array<string, string>}|null */
    public static function match(string $path): ?array
    {
        $path = '/'.ltrim($path, '/');
        foreach (self::storefront() as $route) {
            $regex = '#^'.preg_replace('/\\\\\{(\w+)\\\\\}/', '(?P<$1>[^/]+)', preg_quote($route['path'], '#')).'$#';
            if (preg_match($regex, $path, $found)) {
                return ['route' => $route, 'params' => array_filter($found, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        return null;
    }

    /** @return array{slug: string, title: string, description: string}|null  (accepts the legacy ".html" suffix) */
    public static function article(string $slug): ?array
    {
        $slug = preg_replace('/\.html$/', '', $slug);

        return collect(self::config()['articles'])->firstWhere('slug', $slug);
    }

    /** Title and description of a path, or null when the page does not exist (unknown article, unknown path). */
    public static function seoFor(string $path): ?array
    {
        $match = self::match($path);
        if (! $match) {
            return null;
        }
        $route = $match['route'];
        if ($route['page'] === 'article') {
            $article = self::article($match['params']['slug']);

            return $article ? ['title' => $article['title'].' | '.self::site(), 'description' => $article['description']] : null;
        }

        return isset($route['title']) ? ['title' => $route['title'], 'description' => $route['description'] ?? null] : [];
    }

    public static function notFoundSeo(): array
    {
        return ['title' => 'Página no encontrada | '.self::site(), 'noindex' => true];
    }
}
