<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Password policy of the panel (length is checked by the callers' `min`): no repeated characters, no obvious sequences or common
 * words, and not in a known data leak (Have I Been Pwned range API: only 5 characters of the hash leave the server).
 * If that service does not answer in time the password is accepted: it never blocks saving.
 */
class StrongPassword implements ValidationRule
{
    private const COMMON = ['password', 'contrasena', 'contraseña', 'qwerty', 'asdfgh', 'zxcvbn', 'qazwsx', 'letmein', 'admin', 'welcome', 'fincahelena', 'cafefinca', 'iloveyou'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;
        $lower = mb_strtolower($password);

        if (count(array_unique(mb_str_split($lower))) < 4) {
            $fail('La contraseña no puede repetir los mismos caracteres.');

            return;
        }
        if ($this->hasRun($lower)) {
            $fail('La contraseña no puede tener secuencias como 12345 o abcde.');

            return;
        }
        foreach (self::COMMON as $word) {
            if (str_contains($lower, $word)) {
                $fail('Esa contraseña es demasiado común. Elige otra.');

                return;
            }
        }
        if (config('security.check_pwned_passwords') && $this->leaked($password)) {
            $fail('Esa contraseña apareció en filtraciones de datos conocidas. Elige otra.');
        }
    }

    /** Five or more consecutive characters going up or down by one (12345, abcde, 98765, edcba). */
    private function hasRun(string $value): bool
    {
        $codes = array_map('mb_ord', mb_str_split($value));
        for ($i = 0, $up = 1, $down = 1; $i < count($codes) - 1; $i++) {
            $step = $codes[$i + 1] - $codes[$i];
            $up = $step === 1 ? $up + 1 : 1;
            $down = $step === -1 ? $down + 1 : 1;
            if ($up >= 5 || $down >= 5) {
                return true;
            }
        }

        return false;
    }

    private function leaked(string $password): bool
    {
        try {
            return ! app(UncompromisedVerifier::class)->verify(['value' => $password, 'threshold' => 0]);
        } catch (Throwable) {
            return false;
        }
    }
}
