<?php

namespace App\Support;

/** Sensitive fields (by name, see config shop.activity.redact) never reach the audit trail, not even partially. */
class ActivityRedactor
{
    public const MASK = '[oculto]';

    public static function clean(array $data): array
    {
        $words = array_filter(array_map('mb_strtolower', (array) config('shop.activity.redact', [])));
        $walk = function (array $items) use (&$walk, $words): array {
            foreach ($items as $key => $value) {
                $name = mb_strtolower((string) $key);
                $sensitive = collect($words)->contains(fn ($word) => str_contains($name, $word));
                if ($sensitive) {
                    // A `{field: [before, after]}` pair keeps its shape so the drawer can still draw it.
                    $items[$key] = is_array($value) && array_is_list($value) && count($value) === 2 ? [self::MASK, self::MASK] : self::MASK;
                } elseif (is_array($value)) {
                    $items[$key] = $walk($value);
                }
            }

            return $items;
        };

        return $walk($data);
    }

    /** "ana.perez@dominio.com" becomes "a***@dominio.com". Returns null when the text is not an email. */
    public static function maskEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
