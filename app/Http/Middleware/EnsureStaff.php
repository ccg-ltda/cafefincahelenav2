<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Door of the whole panel (replaces EnsureAdministrator). A person gets in only if signed in, active, with a session that is
 * still current (`users.session_version` is bumped when the role changes, the person is deactivated or the password changes)
 * and, when a temporary password is pending, only to the screens that change it. What each person may DO is decided
 * afterwards, per route, with `can:<permission>` (Gates come from App\Support\Permissions).
 */
class EnsureStaff
{
    /** Reachable while a temporary password is pending. */
    private const PASSWORD_PATHS = ['admin/session', 'admin/password', 'admin/logout', 'admin/change-password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->signedOut($request);
        }

        $stored = $request->session()->get('staff_version');
        if (! $user->is_active || ($stored !== null && (int) $stored !== (int) $user->session_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->signedOut($request);
        }
        if ($stored === null) {
            $request->session()->put('staff_version', (int) $user->session_version); // a session that predates the roles adopts the current version
        }

        if ($user->must_change_password && ! in_array(trim($request->path(), '/'), self::PASSWORD_PATHS, true)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Debes crear una nueva contraseña antes de continuar.', 'must_change_password' => true], 403)
                : redirect('/admin/change-password');
        }

        return $next($request);
    }

    private function signedOut(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => 'Autenticación requerida.'], 401)
            : redirect('/admin/login');
    }
}
