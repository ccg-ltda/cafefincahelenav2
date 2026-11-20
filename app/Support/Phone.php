<?php

namespace App\Support;

class Phone
{
    /** Colombian mobile: 10 digits starting with 3 (after normalization). */
    public const MOBILE_REGEX = '/^3\d{9}$/';

    /** Accepts "300 123 4567", "300-123-4567" and "+57 300 123 4567"; returns only the digits (10 for a valid mobile). */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/[\s().-]/', '', trim($phone));

        return preg_replace('/^(\+57|57)(?=3\d{9}$)/', '', $digits);
    }
}
