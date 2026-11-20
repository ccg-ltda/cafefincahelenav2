<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\ActivityLogger;
use App\Services\LoginThrottle;
use App\Services\OrderStatusService;
use App\Services\UserService;
use App\Support\ActivityRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminSessionController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity, private readonly UserService $users, private readonly LoginThrottle $throttle) {}

    /** Sign in with username (or email, for whoever has one) and password. Every failure answers the same generic message. */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'], // older clients still send the field as "email"
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $login = Str::lower(trim((string) ($input['login'] ?? $input['email'] ?? '')));
        $password = $input['password'];

        // Two independent limits: per ACCOUNT (progressive lock, whatever the IP) and per IP (generous, so one address cannot lock out a whole team).
        $ipKey = 'admin-login-ip:'.$request->ip();
        $locked = $this->throttle->lockedFor($login);
        if ($locked > 0 || RateLimiter::tooManyAttempts($ipKey, (int) config('security.login.ip_attempts'))) {
            return response()->json(['message' => 'Demasiados intentos. Inténtalo de nuevo más tarde.'], 429)
                ->header('Retry-After', (string) max($locked, RateLimiter::availableIn($ipKey), 1));
        }

        $user = User::query()->when(str_contains($login, '@'), fn ($query) => $query->whereRaw('lower(email) = ?', [$login]), fn ($query) => $query->where('username', $login))->first();
        // Always one password check, also when the user does not exist (against a dummy hash): the time taken says nothing about it.
        $valid = Hash::check($password, $user?->password ?? $this->dummyHash()) && $user;

        if (! $valid || ! $user->is_active) {
            RateLimiter::hit($ipKey, (int) config('security.login.ip_window'));
            $this->recordFailure($login, $user && ! $user->is_active ? 'usuario desactivado' : null);
            $failure = $this->throttle->recordFailure($login);
            if ($failure['locked'] > 0 && $user) {
                $this->recordLock($login, $failure['attempts'], $failure['locked']);
            }
            throw ValidationException::withMessages(['login' => 'Las credenciales no son válidas.']);
        }

        $this->throttle->clear($login);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('staff_version', (int) $user->session_version);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->activity->log('auth', 'login', null, [], [], 'Inicio de sesión');

        return response()->json(['authenticated' => true, 'home' => $user->role->home(), 'must_change_password' => $user->must_change_password]);
    }

    /** Who is signed in and what they may do: the frontend builds the menu and the buttons from `permissions`. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'role' => $user->role->value, 'role_label' => $user->role->label(),
            'permissions' => $user->permissions(), 'home' => $user->role->home(), 'must_change_password' => (bool) $user->must_change_password,
            'order_statuses' => OrderStatusService::LABELS, // the ONE list of order labels: the frontend never keeps its own copy
        ])->header('Cache-Control', 'private, no-store');
    }

    /** The screen "Crea tu nueva contraseña": only while a temporary password is pending. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string', 'min:'.UserService::MIN_PASSWORD, 'max:100', 'confirmed', new StrongPassword]], [
            'password.min' => 'La contraseña debe tener al menos '.UserService::MIN_PASSWORD.' caracteres.', 'password.confirmed' => 'Las contraseñas no coinciden.',
        ], ['password' => 'contraseña']);

        if (Hash::check($data['password'], $user->password) || Str::lower($data['password']) === Str::lower((string) $user->username)) {
            throw ValidationException::withMessages(['password' => ['Elige una contraseña distinta de la temporal y de tu usuario.']]);
        }
        $this->users->changeOwnPassword($user, $data['password']);
        $request->session()->put('staff_version', (int) $user->fresh()->session_version); // this session stays open, every other one ends

        return response()->json(['changed' => true, 'home' => $user->role->home()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($request->user()) {
            $this->activity->log('auth', 'logout', null, [], [], 'Cierre de sesión');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['authenticated' => false]);
    }

    /** Hash of a random password, made once with the current cost: what an unknown user is "checked" against. */
    private function dummyHash(): string
    {
        return Cache::rememberForever('admin-login-dummy-hash', fn () => Hash::make(Str::random(40)));
    }

    /** Historial row when a REAL account gets locked (never the password; the typed name masked like a failed attempt). */
    private function recordLock(string $login, int $attempts, int $seconds): void
    {
        $login = mb_substr($login, 0, 100);
        $masked = str_contains($login, '@') ? (ActivityRedactor::maskEmail($login) ?? '[valor no válido]') : (preg_match('/^[a-z0-9.-]{3,40}$/', $login) ? mb_substr($login, 0, 1).'***' : '[valor no válido]');
        $minutes = (int) ceil($seconds / 60);
        $this->activity->log('auth', 'account_locked', null, [], ['login' => $masked, 'attempts' => $attempts, 'minutes' => $minutes], "Cuenta bloqueada tras {$attempts} intentos fallidos ({$minutes} min)", null, $masked);
    }

    /** Failed sign-in: what was typed, masked (an email shows `a***@dominio.com`, a username `a***`); never the password. */
    private function recordFailure(string $login, ?string $note): void
    {
        $login = mb_substr($login, 0, 100);
        $masked = str_contains($login, '@')
            ? (ActivityRedactor::maskEmail($login) ?? '[valor no válido]')
            : (preg_match('/^[a-z0-9.-]{3,40}$/', $login) ? mb_substr($login, 0, 1).'***' : '[valor no válido]');
        $this->activity->log('auth', 'login_failed', null, [], ['login' => $masked] + ($note ? ['note' => $note] : []), 'Inicio de sesión', null, $masked);
    }
}
