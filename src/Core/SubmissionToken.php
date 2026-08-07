<?php

declare(strict_types=1);

namespace Lumera\Core;

/**
 * Single-use, session-bound, expiring submission token.
 *
 * Issued when the public session starts and consumed on submit, so a double
 * click, a retried request or a replayed payload cannot create two leads.
 */
final class SubmissionToken
{
    private const KEY = '_submission_tokens';
    private const TTL = 7200;   // 2 hours
    private const MAX = 5;      // concurrent live tokens per session

    public static function issue(): string
    {
        Session::start();

        $tokens = self::tokens();
        $now    = time();

        // Drop expired entries, then cap the pool.
        $tokens = array_filter($tokens, static fn ($meta) => ($meta['expires'] ?? 0) > $now);

        if (count($tokens) >= self::MAX) {
            $tokens = array_slice($tokens, -(self::MAX - 1), null, true);
        }

        $token = bin2hex(random_bytes(32));
        $tokens[$token] = ['expires' => $now + self::TTL, 'used' => false, 'issued' => $now];

        Session::set(self::KEY, $tokens);

        return $token;
    }

    /**
     * Checks a token without consuming it.
     *
     * Verification happens early in the request so an obviously replayed
     * payload is rejected before any work is done; the token is only burned
     * once the lead is about to be stored, so a visitor who mistypes their
     * email can correct it and resubmit.
     *
     * @return array{ok: bool, reason?: string, issued_at?: int}
     */
    public static function verify(?string $token): array
    {
        Session::start();

        if (!is_string($token) || $token === '') {
            return ['ok' => false, 'reason' => 'missing'];
        }

        $tokens = self::tokens();

        if (!isset($tokens[$token])) {
            return ['ok' => false, 'reason' => 'unknown'];
        }

        $meta = $tokens[$token];

        if (($meta['used'] ?? false) === true) {
            return ['ok' => false, 'reason' => 'already_used'];
        }

        if ((int) ($meta['expires'] ?? 0) <= time()) {
            unset($tokens[$token]);
            Session::set(self::KEY, $tokens);

            return ['ok' => false, 'reason' => 'expired'];
        }

        return ['ok' => true, 'issued_at' => (int) ($meta['issued'] ?? 0)];
    }

    /**
     * Verifies and burns the token in one step. Call this immediately before
     * the lead is written.
     *
     * @return array{ok: bool, reason?: string, issued_at?: int}
     */
    public static function consume(?string $token): array
    {
        $result = self::verify($token);

        if (!$result['ok']) {
            return $result;
        }

        $tokens = self::tokens();
        $tokens[(string) $token]['used'] = true;
        Session::set(self::KEY, $tokens);

        return $result;
    }

    /**
     * Un-burns a token.
     *
     * The token is consumed just before the insert so a double submit cannot
     * race, but if the insert then fails the visitor must be able to retry —
     * otherwise they are locked out with no lead stored. Only a genuinely
     * persisted lead leaves the token spent.
     */
    public static function release(?string $token): void
    {
        Session::start();

        if (!is_string($token) || $token === '') {
            return;
        }

        $tokens = self::tokens();

        if (!isset($tokens[$token])) {
            return;
        }

        $tokens[$token]['used'] = false;
        Session::set(self::KEY, $tokens);
    }

    /**
     * Seconds elapsed since the token was issued.
     *
     * Returns null when the issue time is unknown, so the caller can tell
     * "instant submission" (0 seconds — exactly what a bot does) apart from
     * "cannot tell", instead of treating both as a pass.
     */
    public static function elapsed(int $issuedAt): ?int
    {
        return $issuedAt > 0 ? max(0, time() - $issuedAt) : null;
    }

    /** @return array<string, array<string,mixed>> */
    private static function tokens(): array
    {
        $tokens = Session::get(self::KEY, []);

        return is_array($tokens) ? $tokens : [];
    }
}
