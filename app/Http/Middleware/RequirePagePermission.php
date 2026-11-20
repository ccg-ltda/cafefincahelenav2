<?php

namespace App\Http\Middleware;

use App\Support\SpaRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission of an admin PAGE (the `permission` of routes/spa.json). Without it the answer is the app shell with HTTP 403,
 * so React shows "No tienes acceso a esta sección" with a link to the person's home. JSON endpoints use `can:` and answer 403 JSON.
 */
class RequirePagePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if ($request->user()?->hasPermission($permission)) {
            return $next($request);
        }

        $seo = SpaRoutes::adminSeo(['title' => 'Sin acceso']);

        return response()->view('app', ['seo' => $seo], 403);
    }
}
