<?php

declare(strict_types=1);

/**
 * Lumera Lead Capture — command line tool.
 *
 * Usage:
 *   php bin/console.php key:generate
 *   php bin/console.php install
 *   php bin/console.php migrate
 *   php bin/console.php seed
 *   php bin/console.php admin:create [email] [password]
 *   php bin/console.php admin:password <email> <password>
 *   php bin/console.php admin:list
 *   php bin/console.php funnel:publish [slug]
 *   php bin/console.php funnel:status [slug]
 *   php bin/console.php prune
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Config;
use Lumera\Core\Database;
use Lumera\Repositories\AdminUserRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Services\AnalyticsService;
use Lumera\Services\PublishService;

App::boot($basePath);

$command = $argv[1] ?? 'help';
$args    = array_slice($argv, 2);

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

/**
 * Splits a .sql file into individual statements.
 * The schema/seed files contain no stored routines, so a semicolon split is
 * sufficient and avoids a dependency on the mysql client binary.
 *
 * @return list<string>
 */
function sqlStatements(string $path): array
{
    $sql = file_get_contents($path);

    if ($sql === false) {
        fail("Unable to read {$path}");
    }

    $statements = [];
    $buffer     = '';
    $inString   = false;
    $stringChar = '';
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // Skip line comments outside strings.
        if (!$inString && (($char === '-' && $next === '-') || $char === '#')) {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        if ($inString) {
            if ($char === '\\') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            if ($char === $stringChar) {
                $inString = false;
            }
        } elseif ($char === "'" || $char === '"') {
            $inString   = true;
            $stringChar = $char;
        } elseif ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);

    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function runSqlFile(string $path): int
{
    $statements = sqlStatements($path);
    $pdo = Database::pdo();
    $count = 0;

    foreach ($statements as $statement) {
        // PREPARE/EXECUTE in the conditional-DDL migrations returns a result
        // set that must be consumed before the next statement can run.
        $stmt = $pdo->query($statement);

        if ($stmt !== false) {
            $stmt->closeCursor();
        }

        $count++;
    }

    return $count;
}

/**
 * Applies every file in database/migrations in filename order.
 * The migrations are written to be idempotent, so re-running is safe.
 */
function runMigrations(string $basePath): array
{
    $files = glob($basePath . '/database/migrations/*.sql') ?: [];
    sort($files);

    $applied = [];

    foreach ($files as $file) {
        $statements = runSqlFile($file);
        $applied[basename($file)] = $statements;
    }

    return $applied;
}

function promptHidden(string $label): string
{
    fwrite(STDOUT, $label);

    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows has no stty; the value is still never echoed back or logged.
        $value = trim((string) fgets(STDIN));
        fwrite(STDOUT, PHP_EOL);

        return $value;
    }

    @shell_exec('stty -echo');
    $value = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);

    return $value;
}

try {
    switch ($command) {
        // ------------------------------------------------------------------
        case 'key:generate':
            $key = base64_encode(random_bytes(32));
            out('APP_SECRET=' . $key);
            out('');
            out('Copy this line into your .env file. Changing it later invalidates');
            out('existing IP hashes and submission fingerprints (leads are unaffected).');
            break;

        // ------------------------------------------------------------------
        case 'migrate':
            $applied = runSqlFile($basePath . '/database/schema.sql');
            out("Schema applied ({$applied} statements).");

            foreach (runMigrations($basePath) as $name => $count) {
                out("  migration {$name} ({$count} statements).");
            }
            break;

        // ------------------------------------------------------------------
        case 'seed':
            $applied = runSqlFile($basePath . '/database/seed.sql');
            out("Seed applied ({$applied} statements).");
            break;

        // ------------------------------------------------------------------
        case 'install':
            out('→ Applying schema…');
            $applied = runSqlFile($basePath . '/database/schema.sql');
            out("  {$applied} statements applied.");

            out('→ Applying migrations…');
            foreach (runMigrations($basePath) as $name => $count) {
                out("  {$name} ({$count} statements).");
            }

            out('→ Seeding the Lumera Property Finder funnel…');
            $applied = runSqlFile($basePath . '/database/seed.sql');
            out("  {$applied} statements applied.");

            out('→ Creating the initial admin (if configured)…');
            $repo  = new AdminUserRepository();
            $email = Config::string('ADMIN_INITIAL_EMAIL', '');
            $pass  = Config::string('ADMIN_INITIAL_PASSWORD', '');

            if ($email === '' || $pass === '') {
                out('  Skipped: ADMIN_INITIAL_EMAIL / ADMIN_INITIAL_PASSWORD not set.');
                out('  Run: php bin/console.php admin:create');
            } elseif ($repo->findByEmail($email) !== null) {
                out('  Skipped: that admin already exists (existing accounts are never overwritten).');
            } else {
                $id = $repo->create($email, $pass);
                out("  Admin #{$id} created for {$email}.");
                out('  Remove ADMIN_INITIAL_PASSWORD from .env now.');
            }

            out('→ Publishing funnel version 1…');
            $funnels = new FunnelRepository();
            $funnel  = $funnels->primary();

            if ($funnel === null) {
                out('  Skipped: no funnel found.');
            } elseif ((int) $funnel['published_version'] > 0) {
                out('  Skipped: the funnel is already published (v' . (int) $funnel['published_version'] . ').');
            } else {
                $result = (new PublishService())->publish((int) $funnel['id'], null, 'Initial installation');
                out("  Published version {$result['version']} with {$result['steps']} steps.");
            }

            out('');
            out('Installation complete.');
            out('  Public funnel : ' . (Config::appUrl() ?: 'http://localhost:8080') . '/');
            out('  Admin login   : ' . (Config::appUrl() ?: 'http://localhost:8080') . '/admin/');
            break;

        // ------------------------------------------------------------------
        case 'admin:create':
            $repo  = new AdminUserRepository();
            $email = $args[0] ?? Config::string('ADMIN_INITIAL_EMAIL', '');
            $pass  = $args[1] ?? Config::string('ADMIN_INITIAL_PASSWORD', '');

            if ($email === '') {
                fwrite(STDOUT, 'Email: ');
                $email = trim((string) fgets(STDIN));
            }

            if ($pass === '') {
                $pass = promptHidden('Password (min 12 chars): ');
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                fail('A valid email address is required.');
            }

            if (strlen($pass) < 12) {
                fail('The password must be at least 12 characters.');
            }

            if ($repo->findByEmail($email) !== null) {
                fail("An admin with the email {$email} already exists. Use admin:password to change the password.");
            }

            $id = $repo->create($email, $pass);
            out("Admin #{$id} created for {$email}.");
            out('The password was hashed with password_hash() and is not stored in plain text.');
            break;

        // ------------------------------------------------------------------
        case 'admin:password':
            $repo  = new AdminUserRepository();
            $email = $args[0] ?? '';
            $pass  = $args[1] ?? '';

            if ($email === '') {
                fwrite(STDOUT, 'Email: ');
                $email = trim((string) fgets(STDIN));
            }

            if ($pass === '') {
                $pass = promptHidden('New password (min 12 chars): ');
            }

            $user = $repo->findByEmail($email);

            if ($user === null) {
                fail("No admin found with the email {$email}.");
            }

            if (strlen($pass) < 12) {
                fail('The password must be at least 12 characters.');
            }

            $repo->setPassword((int) $user['id'], $pass);
            out("Password updated for {$email}.");
            break;

        // ------------------------------------------------------------------
        case 'admin:list':
            foreach ((new AdminUserRepository())->all() as $user) {
                out(sprintf(
                    '#%-3d %-40s %-10s last login: %s',
                    $user['id'],
                    $user['email'],
                    ((int) $user['is_active'] === 1 ? 'active' : 'disabled'),
                    $user['last_login_at'] ?? 'never'
                ));
            }
            break;

        // ------------------------------------------------------------------
        case 'funnel:publish':
            $funnels = new FunnelRepository();
            $funnel  = isset($args[0]) ? $funnels->findBySlug($args[0]) : $funnels->primary();

            if ($funnel === null) {
                fail('Funnel not found.');
            }

            $result = (new PublishService())->publish((int) $funnel['id'], null, 'Published from CLI');
            out("Published {$funnel['slug']} version {$result['version']} ({$result['steps']} steps) at {$result['published_at']}.");
            break;

        // ------------------------------------------------------------------
        case 'funnel:status':
            $funnels = new FunnelRepository();
            $funnel  = isset($args[0]) ? $funnels->findBySlug($args[0]) : $funnels->primary();

            if ($funnel === null) {
                fail('Funnel not found.');
            }

            $status = (new PublishService())->status((int) $funnel['id']);

            out("Funnel            : {$funnel['name']} ({$funnel['slug']})");
            out('Published version : ' . $status['published_version']);
            out('Published at      : ' . ($status['published_at'] ?? 'never'));
            out('Draft changed     : ' . ($status['has_unpublished'] ? 'yes' : 'no'));

            if ($status['publish_blockers'] !== []) {
                out('Blockers          :');
                foreach ($status['publish_blockers'] as $blocker) {
                    out('  - ' . $blocker);
                }
            }
            break;

        // ------------------------------------------------------------------
        case 'analytics:rollup':
            if (!AnalyticsService::enabled()) {
                out('Analytics is disabled (ANALYTICS_ENABLED=false). Nothing to do.');
                break;
            }

            $days = isset($args[0]) ? (int) $args[0] : 7;
            $result = (new AnalyticsService())->rollup($days);

            out("Closed {$result['abandoned']} abandoned session(s).");
            out("Rebuilt {$result['funnels']} funnel-day rollup(s) across {$result['days']} day(s).");
            break;

        // ------------------------------------------------------------------
        case 'analytics:prune':
            if (!AnalyticsService::enabled()) {
                out('Analytics is disabled (ANALYTICS_ENABLED=false). Nothing to do.');
                break;
            }

            $retention = AnalyticsService::retentionDays();
            $deleted = (new AnalyticsService())->prune();

            out("Deleted {$deleted} raw analytics event(s) older than {$retention} days.");
            out('Sessions, rollups and leads were not touched.');
            break;

        // ------------------------------------------------------------------
        case 'prune':
            Database::execute('DELETE FROM `rate_limit_entries` WHERE `expires_at` < NOW()');
            $attempts = Database::execute('DELETE FROM `login_attempts` WHERE `attempted_at` < (NOW() - INTERVAL 30 DAY)');
            out("Pruned expired rate-limit windows and {$attempts} old login attempts.");
            break;

        // ------------------------------------------------------------------
        default:
            out('Lumera Lead Capture — console');
            out('');
            out('  key:generate                       Generate an APP_SECRET value');
            out('  install                            Schema + seed + first admin + publish v1');
            out('  migrate                            Apply database/schema.sql');
            out('  seed                               Apply database/seed.sql');
            out('  admin:create [email] [password]    Create an admin (prompts if omitted)');
            out('  admin:password <email> <password>  Reset an admin password');
            out('  admin:list                         List admin accounts');
            out('  funnel:publish [slug]              Publish the current draft');
            out('  funnel:status [slug]               Show draft / published state');
            out('  prune                              Clean expired rate-limit and login rows');
            out('  analytics:rollup [days]            Close stale sessions and rebuild daily rollups');
            out('  analytics:prune                    Delete raw analytics events past retention');
            break;
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
