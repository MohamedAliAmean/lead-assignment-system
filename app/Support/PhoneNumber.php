<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes phone numbers to digits with an optional leading "+", so that
 * prefix search on idx_leads_phone works regardless of input formatting.
 */
final class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return (str_starts_with($phone, '+') ? '+' : '').$digits;
    }
}
