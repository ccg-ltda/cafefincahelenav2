<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Progressive lock of ONE account, independent of the IP: whoever the attacker is and however many addresses they rotate, the
 * account the failures are aimed at slows down (5 failures: 1 minute, the next failure: 5 minutes, then 15 minutes each time).
 * It works on what was typed, so an unknown name behaves exactly like a real one. A success or an hour without failures resets it.
 * Settings: config('security.login').
 */
class LoginThrottle
{
    /** Seconds the account is still locked for, 0 when free. */
    public function lockedFor(string $login): int
    {
        return max(0, (int) ($this->state($login)['until'] ?? 0) - now()->timestamp);
    }

    /**
     * Counts one failed attempt. Returns the failures so far and, when THIS failure started a lock, the seconds it lasts (otherwise 0).
     *
     * @return array{attempts: int, locked: int}
     */
    public function recordFailure(string $login): array
    {
        $settings = config('security.login');
        $state = $this->state($login);
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $locked = 0;
        if ($attempts >= $settings['lock_after']) {
            $steps = $settings['lock_steps'];
            $locked = (int) $steps[min($attempts - $settings['lock_after'], count($steps) - 1)];
        }
        Cache::put($this->key($login), ['attempts' => $attempts, 'until' => $locked ? now()->timestamp + $locked : 0], (int) $settings['forget_after'] + $locked);

        return ['attempts' => $attempts, 'locked' => $locked];
    }

    public function clear(string $login): void
    {
        Cache::forget($this->key($login));
    }

    private function state(string $login): array
    {
        return (array) Cache::get($this->key($login), []);
    }

    private function key(string $login): string
    {
        return 'admin-login-account:'.hash('sha256', mb_strtolower(mb_substr(trim($login), 0, 255)));
    }
}
