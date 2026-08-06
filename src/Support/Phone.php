<?php

declare(strict_types=1);

namespace Lumera\Support;

final class Phone
{
    /**
     * Builds an E.164-ish normalised number from a country code and a local
     * number. No external dependency — deliberately permissive but strict
     * enough to reject junk.
     */
    public static function normalize(?string $countryCode, ?string $number): ?string
    {
        $cc  = preg_replace('/\D+/', '', (string) $countryCode) ?? '';
        $raw = preg_replace('/\D+/', '', (string) $number) ?? '';

        if ($raw === '') {
            return null;
        }

        // Drop trunk prefix (e.g. UAE 050… -> 50…)
        $raw = ltrim($raw, '0');

        if ($cc !== '' && str_starts_with($raw, $cc) && strlen($raw) > strlen($cc) + 5) {
            $raw = substr($raw, strlen($cc));
        }

        $full = $cc . $raw;

        if (strlen($full) < 8 || strlen($full) > 15) {
            return null;
        }

        return '+' . $full;
    }

    public static function isValid(?string $countryCode, ?string $number): bool
    {
        return self::normalize($countryCode, $number) !== null;
    }

    public static function isValidCountryCode(?string $countryCode): bool
    {
        $cc = preg_replace('/\D+/', '', (string) $countryCode) ?? '';

        return strlen($cc) >= 1 && strlen($cc) <= 4;
    }

    /** Digits only, for wa.me links. */
    public static function whatsappDigits(?string $normalized): string
    {
        return preg_replace('/\D+/', '', (string) $normalized) ?? '';
    }
}
