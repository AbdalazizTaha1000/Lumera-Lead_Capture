<?php

declare(strict_types=1);

namespace Lumera\Support;

/**
 * Normalises where a visit came from.
 *
 * Both forms are kept: the raw referrer and UTM values exactly as received, and
 * a single normalised bucket for reporting. Attribution phases later can reuse
 * the raw values without re-deriving them.
 */
final class TrafficSource
{
    public const DIRECT   = 'direct';
    public const ORGANIC  = 'organic';
    public const SOCIAL   = 'social';
    public const PAID     = 'paid';
    public const REFERRAL = 'referral';
    public const OTHER    = 'other';

    private const SEARCH_ENGINES = [
        'google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.',
        'baidu.com', 'ecosia.org', 'search.brave.com', 'qwant.com', 'startpage.com',
        'ask.com', 'aol.com',
    ];

    private const SOCIAL_NETWORKS = [
        'facebook.com', 'fb.com', 'l.facebook', 'instagram.com', 'l.instagram',
        'twitter.com', 't.co', 'x.com', 'linkedin.com', 'lnkd.in',
        'tiktok.com', 'snapchat.com', 'pinterest.', 'reddit.com',
        'youtube.com', 'youtu.be', 'whatsapp.com', 'wa.me', 't.me', 'telegram',
        'threads.net', 'quora.com',
    ];

    private const PAID_MEDIUMS = [
        'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search',
        'paidsocial', 'paid_social', 'paid-social', 'display', 'banner',
        'cpm', 'cpv', 'retargeting', 'remarketing', 'affiliate',
    ];

    /**
     * @param array<string,mixed> $utm keys: source, medium, campaign, content, term
     */
    public static function classify(?string $referrer, array $utm): string
    {
        $medium = strtolower(trim((string) ($utm['medium'] ?? '')));
        $source = strtolower(trim((string) ($utm['source'] ?? '')));

        // Paid is decided by the campaign tagging, never by the referrer.
        if ($medium !== '' && in_array($medium, self::PAID_MEDIUMS, true)) {
            return self::PAID;
        }

        if ($source !== '' && (str_contains($source, 'adwords') || str_contains($source, 'googleads'))) {
            return self::PAID;
        }

        if ($medium === 'organic') { return self::ORGANIC; }
        if ($medium === 'social') { return self::SOCIAL; }
        if ($medium === 'referral') { return self::REFERRAL; }
        if ($medium === 'email' || $medium === 'newsletter') { return self::OTHER; }

        $domain = self::domain($referrer);

        if ($domain === null) {
            // A tagged visit with no referrer is still attributable.
            return ($source !== '' || $medium !== '') ? self::OTHER : self::DIRECT;
        }

        foreach (self::SEARCH_ENGINES as $needle) {
            if (str_contains($domain, $needle)) { return self::ORGANIC; }
        }

        foreach (self::SOCIAL_NETWORKS as $needle) {
            if (str_contains($domain, $needle)) { return self::SOCIAL; }
        }

        return self::REFERRAL;
    }

    /** Host of a referrer, lower-cased and without "www.". Null when unusable. */
    public static function domain(?string $referrer): ?string
    {
        $referrer = trim((string) $referrer);

        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return preg_replace('/^www\./', '', $host) ?: null;
    }

    /**
     * Keeps a referrer safe to store and display: http(s) only, no credentials,
     * no query string (which routinely carries tokens and personal data).
     */
    public static function sanitize(?string $referrer): ?string
    {
        $referrer = trim((string) $referrer);

        if ($referrer === '' || mb_strlen($referrer) > 2000) {
            return null;
        }

        $parts = parse_url($referrer);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $clean = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);

        if (isset($parts['port'])) {
            $clean .= ':' . (int) $parts['port'];
        }

        if (isset($parts['path'])) {
            $clean .= $parts['path'];
        }

        return mb_substr($clean, 0, 500);
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return [self::DIRECT, self::ORGANIC, self::SOCIAL, self::PAID, self::REFERRAL, self::OTHER];
    }
}
