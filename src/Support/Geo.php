<?php

declare(strict_types=1);

namespace Lumera\Support;

/**
 * Geo resolution from what the infrastructure already provides.
 *
 * Never performs an outbound lookup: a per-view HTTP call to a geo service
 * would add latency to every page view and a third-party dependency to every
 * visit. If no proxy or GeoIP module supplies the data, everything stays NULL
 * and the reports degrade gracefully.
 */
final class Geo
{
    /** Country headers set by common CDNs and reverse proxies. */
    private const COUNTRY_HEADERS = [
        'HTTP_CF_IPCOUNTRY',            // Cloudflare
        'HTTP_X_VERCEL_IP_COUNTRY',
        'HTTP_X_APPENGINE_COUNTRY',
        'HTTP_X_COUNTRY_CODE',
        'HTTP_X_GEOIP_COUNTRY',
        'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
        'GEOIP_COUNTRY_CODE',           // Apache mod_geoip / mod_maxminddb
        'MM_COUNTRY_CODE',
    ];

    private const CITY_HEADERS = [
        'HTTP_CF_IPCITY',
        'HTTP_X_VERCEL_IP_CITY',
        'HTTP_X_APPENGINE_CITY',
        'HTTP_CLOUDFRONT_VIEWER_CITY',
        'GEOIP_CITY',
        'MM_CITY_NAME',
    ];

    private const NAME_HEADERS = [
        'GEOIP_COUNTRY_NAME',
        'MM_COUNTRY_NAME',
    ];

    /**
     * @return array{country_code: ?string, country_name: ?string, city: ?string}
     */
    public static function resolve(): array
    {
        $code = self::firstHeader(self::COUNTRY_HEADERS);

        if ($code !== null) {
            $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $code) ?? '');

            // Cloudflare uses XX for unknown and T1 for Tor.
            if (strlen($code) !== 2 || in_array($code, ['XX', 'T1'], true)) {
                $code = null;
            }
        }

        $name = self::firstHeader(self::NAME_HEADERS);

        if ($name === null && $code !== null) {
            $name = self::COUNTRY_NAMES[$code] ?? null;
        }

        $city = self::firstHeader(self::CITY_HEADERS);

        if ($city !== null) {
            // City names arrive percent- or latin1-encoded from some proxies.
            $city = Str::clean(rawurldecode($city), 120);
            if ($city === '') { $city = null; }
        }

        return [
            'country_code' => $code,
            'country_name' => $name !== null ? Str::clean($name, 80) : null,
            'city'         => $city,
        ];
    }

    private static function firstHeader(array $names): ?string
    {
        foreach ($names as $name) {
            $value = $_SERVER[$name] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Names for the codes most likely to appear; unknown codes stay as codes. */
    private const COUNTRY_NAMES = [
        'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
        'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman', 'EG' => 'Egypt',
        'JO' => 'Jordan', 'LB' => 'Lebanon', 'IQ' => 'Iraq', 'SY' => 'Syria',
        'GB' => 'United Kingdom', 'US' => 'United States', 'CA' => 'Canada',
        'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka',
        'PH' => 'Philippines', 'ID' => 'Indonesia', 'MY' => 'Malaysia', 'SG' => 'Singapore',
        'CN' => 'China', 'HK' => 'Hong Kong', 'JP' => 'Japan', 'KR' => 'South Korea',
        'AU' => 'Australia', 'NZ' => 'New Zealand',
        'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain',
        'NL' => 'Netherlands', 'BE' => 'Belgium', 'CH' => 'Switzerland', 'AT' => 'Austria',
        'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
        'IE' => 'Ireland', 'PT' => 'Portugal', 'PL' => 'Poland', 'RO' => 'Romania',
        'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Türkiye', 'GR' => 'Greece',
        'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'MA' => 'Morocco',
        'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile',
    ];

    public static function countryName(?string $code): ?string
    {
        if ($code === null) { return null; }

        return self::COUNTRY_NAMES[strtoupper($code)] ?? strtoupper($code);
    }
}
