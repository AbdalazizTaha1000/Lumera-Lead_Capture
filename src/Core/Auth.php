<?php

declare(strict_types=1);

namespace Lumera\Core;

use Lumera\Support\Request;

/**
 * Session-based admin authentication.
 *
 * There is no public registration path anywhere in the application: admins are
 * created only through bin/console.php.
 */
final class Auth
{
    private const SESSION_USER   = '_admin_user_id';
    private const SESSION_ACTIVE = '_admin_last_activity';
    private const SESSION_AGENT  = '_admin_agent_fingerprint';

    /** @var array<string,mixed>|null */
    private static ?array $cachedUser = null;

    /**
     * @return array{ok: bool, error?: string, retry_after?: int, user?: array<string,mixed>}
     */
    public static function attempt(string $email, string $password): array
    {
        Session::start();

        $email = mb_strtolower(trim($email));
        $ip    = Request::ip();

        $maxAttempts = Config::int('LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5);
        $window      = Config::int('LOGIN_RATE_LIMIT_WINDOW_SECONDS', 900);

        $byIp = RateLimiter::hit('login_ip', $ip, $maxAttempts, $window);

        if (!$byIp['allowed']) {
            self::recordAttempt($email, false);
            return ['ok' => false, 'error' => 'Too many attempts. Please try again later.', 'retry_after' => $byIp['retry_after']];
        }

        $byEmail = RateLimiter::hit('login_email', $email, $maxAttempts * 2, $window);

        if (!$byEmail['allowed']) {
            self::recordAttempt($email, false);
            return ['ok' => false, 'error' => 'Too many attempts. Please try again later.', 'retry_after' => $byEmail['retry_after']];
        }

        $user = Database::selectOne(
            'SELECT * FROM `admin_users` WHERE `email` = :email LIMIT 1',
            ['email' => $email]
        );

        // Constant-ish work factor whether or not the account exists.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $valid = password_verify($password, (string) $hash);

        if ($user === null || !$valid || (int) $user['is_active'] !== 1) {
            self::recordAttempt($email, false);
            AuditLog::record(AuditLog::LOGIN_FAILURE, 'admin_user', $user['id'] ?? null, ['email' => $email], null);
            Logger::warning('auth.login_failed', ['email' => $email, 'ip_hash' => Request::ipHash()]);

            // Deliberately generic — no account enumeration.
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::execute(
                'UPDATE `admin_users` SET `password_hash` = :h WHERE `id` = :id',
                ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['id']]
            );
        }

        Session::regenerate();
        Session::set(self::SESSION_USER, (int) $user['id']);
        Session::set(self::SESSION_ACTIVE, time());
        Session::set(self::SESSION_AGENT, self::fingerprint());
        Csrf::rotate('admin');

        RateLimiter::clear('login_ip', $ip);
        RateLimiter::clear('login_email', $email);

        Database::execute('UPDATE `admin_users` SET `last_login_at` = NOW() WHERE `id` = :id', ['id' => (int) $user['id']]);

        self::recordAttempt($email, true);
        AuditLog::record(AuditLog::LOGIN_SUCCESS, 'admin_user', (int) $user['id'], [], (int) $user['id']);
        Logger::info('auth.login_success', ['admin_user_id' => (int) $user['id']]);

        unset($user['password_hash']);
        self::$cachedUser = $user;

        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        Session::start();
        $id = self::id();

        if ($id !== null) {
            AuditLog::record(AuditLog::LOGOUT, 'admin_user', $id, [], $id);
        }

        self::$cachedUser = null;
        Session::destroy();
    }

    public static function id(): ?int
    {
        Session::start();
        $id = Session::get(self::SESSION_USER);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        Session::start();
        $id = Session::get(self::SESSION_USER);

        if (!is_int($id) || $id <= 0) {
            return null;
        }

        // Bind the session to the user agent to blunt trivial session replay.
        if (Session::get(self::SESSION_AGENT) !== self::fingerprint()) {
            Logger::warning('auth.fingerprint_mismatch', ['admin_user_id' => $id]);
            Session::destroy();
            return null;
        }

        $last = (int) Session::get(self::SESSION_ACTIVE, 0);
        $idle = Config::int('APP_SESSION_IDLE_MINUTES', 60) * 60;

        if ($last > 0 && (time() - $last) > $idle) {
            Session::destroy();
            return null;
        }

        Session::set(self::SESSION_ACTIVE, time());

        $user = Database::selectOne(
            'SELECT `id`, `email`, `name`, `is_active`, `last_login_at`, `created_at`
             FROM `admin_users` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );

        if ($user === null || (int) $user['is_active'] !== 1) {
            Session::destroy();
            return null;
        }

        self::$cachedUser = $user;

        return $user;
    }

    /** Middleware for JSON admin endpoints. */
    public static function requireApi(): array
    {
        $user = self::user();

        if ($user === null) {
            Response::error('Authentication required.', 401);
        }

        return $user;
    }

    /** Middleware for admin HTML pages. */
    public static function requirePage(string $loginUrl = '/admin/login.php'): array
    {
        $user = self::user();

        if ($user === null) {
            Response::redirect($loginUrl);
        }

        return $user;
    }

    /**
     * Guards every state-changing admin endpoint.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed> the authenticated admin
     */
    public static function requireApiWrite(array $body = []): array
    {
        $user = self::requireApi();

        if (!in_array(Request::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            Response::error('Method not allowed.', 405);
        }

        if (!Csrf::validate(Csrf::fromRequest($body), 'admin')) {
            Logger::warning('csrf.admin_rejected', ['admin_user_id' => $user['id'] ?? null]);
            Response::error('Your session has expired. Please refresh the page.', 419);
        }

        return $user;
    }

    private static function fingerprint(): string
    {
        return hash_hmac('sha256', Request::userAgent(), Config::secret());
    }

    private static function recordAttempt(string $email, bool $successful): void
    {
        try {
            Database::execute(
                'INSERT INTO `login_attempts` (`email`, `ip_hash`, `successful`) VALUES (:e, :i, :s)',
                ['e' => mb_substr($email, 0, 190), 'i' => Request::ipHash(), 's' => $successful ? 1 : 0]
            );
        } catch (\Throwable) {
            // non-critical
        }
    }
}
