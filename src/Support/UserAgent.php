<?php

declare(strict_types=1);

namespace Lumera\Support;

/**
 * Lightweight user-agent classification.
 *
 * Deliberately coarse: device class, browser family, OS family and a bot flag.
 * Nothing here fingerprints — no canvas, fonts, hardware or entropy probing.
 * The user-agent string itself is the only input, and it is already sent on
 * every request.
 */
final class UserAgent
{
    /** Substrings that identify automated traffic. Matched case-insensitively. */
    private const BOT_SIGNATURES = [
        'bot', 'crawler', 'spider', 'crawl', 'slurp', 'curl', 'wget', 'python-requests',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'scrapy', 'httpclient',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'slackbot', 'discordbot',
        'embedly', 'pingdom', 'uptimerobot', 'gtmetrix', 'lighthouse', 'ahrefs',
        'semrush', 'mj12bot', 'dotbot', 'petalbot', 'bingpreview', 'yandex',
        'applebot', 'duckduckbot', 'baiduspider', 'go-http-client', 'okhttp',
        'java/', 'libwww', 'perl', 'postman', 'insomnia',
    ];

    /**
     * @return array{
     *   device_type: string, browser: string, browser_version: ?string,
     *   os: string, os_version: ?string, is_bot: bool
     * }
     */
    public static function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);
        $lower = strtolower($ua);

        if ($ua === '') {
            return [
                'device_type' => 'unknown', 'browser' => 'Other', 'browser_version' => null,
                'os' => 'Other', 'os_version' => null, 'is_bot' => true,
            ];
        }

        $isBot = self::isBot($lower);

        return [
            'device_type'     => $isBot ? 'bot' : self::deviceType($lower),
            'browser'         => self::browser($lower),
            'browser_version' => self::browserVersion($ua, $lower),
            'os'              => self::os($lower),
            'os_version'      => self::osVersion($ua, $lower),
            'is_bot'          => $isBot,
        ];
    }

    public static function isBot(string $lowerUserAgent): bool
    {
        foreach (self::BOT_SIGNATURES as $needle) {
            if (str_contains($lowerUserAgent, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function deviceType(string $ua): string
    {
        // Tablets first: an Android tablet UA also contains "android".
        if (str_contains($ua, 'ipad')
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))
            || str_contains($ua, 'tablet')
            || str_contains($ua, 'kindle')
            || str_contains($ua, 'playbook')
            || str_contains($ua, 'silk')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile')
            || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipod')
            || str_contains($ua, 'android')
            || str_contains($ua, 'blackberry')
            || str_contains($ua, 'windows phone')
            || str_contains($ua, 'opera mini')) {
            return 'mobile';
        }

        if (str_contains($ua, 'windows') || str_contains($ua, 'macintosh')
            || str_contains($ua, 'x11') || str_contains($ua, 'linux')
            || str_contains($ua, 'cros')) {
            return 'desktop';
        }

        return 'unknown';
    }

    private static function browser(string $ua): string
    {
        // Order matters: most browsers impersonate Chrome and Safari.
        if (str_contains($ua, 'edg/') || str_contains($ua, 'edga/') || str_contains($ua, 'edgios/')) { return 'Edge'; }
        if (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) { return 'Opera'; }
        if (str_contains($ua, 'samsungbrowser')) { return 'Samsung Internet'; }
        if (str_contains($ua, 'firefox') || str_contains($ua, 'fxios')) { return 'Firefox'; }
        if (str_contains($ua, 'crios') || str_contains($ua, 'chrome') || str_contains($ua, 'chromium')) { return 'Chrome'; }
        if (str_contains($ua, 'safari')) { return 'Safari'; }

        return 'Other';
    }

    private static function browserVersion(string $ua, string $lower): ?string
    {
        $patterns = [
            'Edge'             => '/Edge?[AI]?O?S?\/([0-9]+(?:\.[0-9]+)?)/i',
            'Opera'            => '/(?:OPR|Opera)[\/ ]([0-9]+(?:\.[0-9]+)?)/i',
            'Samsung Internet' => '/SamsungBrowser\/([0-9]+(?:\.[0-9]+)?)/i',
            'Firefox'          => '/(?:Firefox|FxiOS)\/([0-9]+(?:\.[0-9]+)?)/i',
            'Chrome'           => '/(?:CriOS|Chrome|Chromium)\/([0-9]+(?:\.[0-9]+)?)/i',
            'Safari'           => '/Version\/([0-9]+(?:\.[0-9]+)?)/i',
        ];

        $browser = self::browser($lower);

        if (!isset($patterns[$browser])) {
            return null;
        }

        return preg_match($patterns[$browser], $ua, $m) === 1 ? substr($m[1], 0, 20) : null;
    }

    private static function os(string $ua): string
    {
        if (str_contains($ua, 'windows')) { return 'Windows'; }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) { return 'iOS'; }
        if (str_contains($ua, 'android')) { return 'Android'; }
        if (str_contains($ua, 'mac os x') || str_contains($ua, 'macintosh')) { return 'macOS'; }
        if (str_contains($ua, 'cros')) { return 'ChromeOS'; }
        if (str_contains($ua, 'linux') || str_contains($ua, 'x11') || str_contains($ua, 'ubuntu')) { return 'Linux'; }

        return 'Other';
    }

    private static function osVersion(string $ua, string $lower): ?string
    {
        $os = self::os($lower);

        $patterns = [
            'Windows' => '/Windows NT ([0-9]+(?:\.[0-9]+)?)/i',
            'iOS'     => '/OS ([0-9]+(?:[._][0-9]+)?)/i',
            'Android' => '/Android ([0-9]+(?:\.[0-9]+)?)/i',
            'macOS'   => '/Mac OS X ([0-9]+(?:[._][0-9]+)?)/i',
        ];

        if (!isset($patterns[$os]) || preg_match($patterns[$os], $ua, $m) !== 1) {
            return null;
        }

        $version = str_replace('_', '.', $m[1]);

        // Windows reports a kernel version; translate the common ones.
        if ($os === 'Windows') {
            $version = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'][$version] ?? $version;
        }

        return substr($version, 0, 20);
    }
}
