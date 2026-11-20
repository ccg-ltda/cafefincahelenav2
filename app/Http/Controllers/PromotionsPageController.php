<?php

namespace App\Http\Controllers;

use App\Services\PromotionService;
use App\Support\SpaRoutes;
use Illuminate\Http\Response;

/**
 * /promociones: the storefront catalogue with the virtual "Promociones" tab selected (React renders it).
 * It answers 200 either way; without a current promotion the page only explains that and is marked noindex.
 */
class PromotionsPageController extends Controller
{
    public function __invoke(PromotionService $promotions): Response
    {
        $seo = SpaRoutes::seoFor('/promociones');
        if (! $promotions->hasCurrent()) {
            unset($seo['description']);
            $seo['noindex'] = true;
        }

        return response()->view('app', ['seo' => $seo]);
    }
}
