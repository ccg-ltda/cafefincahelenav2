<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\PromotionService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    private const STATIC_PAGES = ['/', '/sello-de-origen', '/blog'];

    public function __invoke(PromotionService $promotions): Response
    {
        $base = rtrim(config('app.url'), '/');
        // /promociones is listed only while there is at least one promotion a customer can use.
        $paths = $promotions->hasCurrent() ? [...self::STATIC_PAGES, '/promociones'] : self::STATIC_PAGES;
        $urls = array_map(fn (string $path) => ['loc' => $base.$path, 'lastmod' => null], $paths);

        // Only canonical product URLs of publicly visible products: no SKU URLs, no ?sku= selections.
        $products = Product::query()->visible()->with(['variants' => fn ($variants) => $variants->where('is_published', true)])->orderBy('sort_order')->orderBy('id')->get();
        foreach ($products as $product) {
            $updated = $product->variants->pluck('updated_at')->push($product->updated_at)->filter()->max();
            $urls[] = ['loc' => $base.'/producto/'.$product->slug, 'lastmod' => $updated?->toAtomString()];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
                .($url['lastmod'] ? '<lastmod>'.$url['lastmod'].'</lastmod>' : '').'</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
