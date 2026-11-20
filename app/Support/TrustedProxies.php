<?php

namespace App\Support;

/** Reads TRUSTED_PROXIES: empty = trust no proxy; "*" only when written explicitly; otherwise a list of IPs / CIDR ranges. */
final class TrustedProxies
{
    /** @return list<string>|string|null null = do not trust any proxy */
    public static function parse(?string $value): array|string|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if ($value === '*') {
            return '*';
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $entry) => $entry !== '' && $entry !== '*'));

        return $list === [] ? null : $list;
    }
}
