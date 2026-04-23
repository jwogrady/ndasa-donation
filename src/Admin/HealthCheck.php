<?php
/**
 * NDASA Donation Platform
 *
 * @package    NDASA\Donation
 * @author     William Cross
 * @author     John O'Grady <john@status26.com>
 * @copyright  2026 NDASA Foundation
 * @license    Proprietary - NDASA Foundation
 * @link       https://ndasafoundation.org/
 *
 * Maintained in honor of William Cross.
 */
declare(strict_types=1);

namespace NDASA\Admin;

use NDASA\Admin\AppConfig;
use NDASA\Support\Database;

/**
 * Admin-side system health checks.
 *
 * Each check returns a simple associative array with `label`, `ok`, and an
 * optional `detail`. The dashboard renders them as a list; nothing here
 * ever throws, so a broken check cannot crash the page.
 */
final class HealthCheck
{
    /**
     * Grouped health checks for the admin dashboard. Three sections:
     *
     *   Database      — connection, schema, indexes
     *   Environment   — writability of the files the app touches at runtime
     *   Configuration — presence of the required env vars
     *
     * Every probe is wrapped so it cannot throw; failures surface as FAIL
     * rows with a human-readable detail string.
     *
     * Returns an associative array with two keys:
     *   - 'groups'          : grouped check rows keyed by section name
     *   - 'missing_indexes' : list of expected indexes currently missing
     *
     * The second value is useful for a separate dashboard banner; callers
     * should not re-run the index probe to get it.
     *
     * @return array{
     *     groups: array<string, list<array{label:string,ok:bool,detail:?string}>>,
     *     missing_indexes: list<string>,
     * }
     */
    public static function all(): array
    {
        [$databaseRows, $missingIndexes] = self::databaseChecks();
        return [
            'groups' => [
                'Database'      => $databaseRows,
                'Environment'   => self::environmentChecks(),
                'Configuration' => self::configurationChecks(),
            ],
            'missing_indexes' => $missingIndexes,
        ];
    }

    /**
     * @return array{0: list<array{label:string,ok:bool,detail:?string}>, 1: list<string>}
     */
    private static function databaseChecks(): array
    {
        $out = [];

        $db = null;
        try {
            $db = Database::connection();
            $out[] = self::row('Database connection', true, null);
        } catch (\Throwable $e) {
            $out[] = self::row('Database connection', false, 'Unable to open: ' . $e->getMessage());
        }

        foreach (['donations', 'page_views', 'stripe_events'] as $table) {
            $out[] = self::row(
                "Table: {$table}",
                $db !== null && self::tableExists($db, $table),
                null,
            );
        }

        $missingIndexes = $db !== null
            ? self::missingIndexes($db)
            : [
                'idx_donations_created_at',
                'idx_donations_status',
                'idx_page_views_created_at',
            ];
        $out[] = self::row(
            'Database indexes',
            $missingIndexes === [],
            $missingIndexes === [] ? null : 'Missing: ' . implode(', ', $missingIndexes),
        );

        return [$out, $missingIndexes];
    }

    /** @return list<array{label:string,ok:bool,detail:?string}> */
    private static function environmentChecks(): array
    {
        $out = [];

        // Repo root is one level above this file's src/Admin/ directory.
        $root = dirname(__DIR__, 2);

        $envPath = $root . '/.env';
        $out[] = self::writableFileCheck('.env writable', $envPath);

        $dbPath = (string) ($_ENV['DB_PATH'] ?? '');
        if ($dbPath === '') {
            $out[] = self::row('Database file writable', false, 'DB_PATH is not set');
        } else {
            $out[] = self::writableFileCheck('Database file writable', $dbPath);
        }

        $logsDir = $root . '/storage/logs';
        $out[] = self::writableDirCheck('Logs directory writable', $logsDir);

        return $out;
    }

    /** @return list<array{label:string,ok:bool,detail:?string}> */
    private static function configurationChecks(): array
    {
        // Non-Stripe required vars. Stripe credentials are mode-selected by
        // bootstrap; if they were missing the app would never reach this code,
        // so probing $_ENV['STRIPE_SECRET_KEY'] here would always pass. The
        // mode panel on the dashboard shows live-ready / test-ready instead.
        $required = [
            'APP_URL',
            'DB_PATH',
            'MAIL_FROM',
            'MAIL_BCC_INTERNAL',
        ];
        $out = [];
        foreach ($required as $key) {
            $out[] = self::row(
                "Env: {$key}",
                !empty($_ENV[$key]),
                null,
            );
        }

        // Stripe: check the source credentials for whichever mode is active.
        $mode = defined('NDASA_STRIPE_MODE') ? NDASA_STRIPE_MODE : AppConfig::MODE_LIVE;
        $stripeOk = AppConfig::resolveStripeCredentials($mode, $_ENV) !== null;
        if ($mode === AppConfig::MODE_TEST) {
            $label  = 'Stripe (TEST mode): key + webhook secret';
            $detail = 'Set STRIPE_TEST_SECRET_KEY and STRIPE_TEST_WEBHOOK_SECRET in .env.';
        } else {
            $label  = 'Stripe (LIVE mode): key + webhook secret';
            $detail = 'Set STRIPE_LIVE_SECRET_KEY and STRIPE_LIVE_WEBHOOK_SECRET in .env (legacy STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET also accepted).';
        }
        $out[] = self::row($label, $stripeOk, $stripeOk ? null : $detail);

        // SMTP is required but satisfied by either a DSN or components.
        $smtpOk = !empty($_ENV['SMTP_DSN']) || !empty($_ENV['SMTP_HOST']);
        $out[] = self::row(
            'Env: SMTP (DSN or HOST)',
            $smtpOk,
            $smtpOk ? null : 'Set SMTP_DSN, or SMTP_HOST plus the SMTP_* components.',
        );

        return $out;
    }

    private static function writableFileCheck(string $label, string $path): array
    {
        try {
            if (!file_exists($path)) {
                // A non-existent file is writable iff its directory is.
                $dir = dirname($path);
                $ok  = is_dir($dir) && is_writable($dir);
                $detail = $ok
                    ? "{$path} does not exist yet (will be created on first write)"
                    : "Parent directory not writable: {$dir}";
                return self::row($label, $ok, $detail);
            }
            $ok = is_writable($path);
            return self::row($label, $ok, $ok ? $path : "{$path} (check file permissions)");
        } catch (\Throwable $e) {
            return self::row($label, false, $e->getMessage());
        }
    }

    private static function writableDirCheck(string $label, string $path): array
    {
        try {
            if (!is_dir($path)) {
                return self::row($label, false, "{$path} does not exist");
            }
            $ok = is_writable($path);
            return self::row($label, $ok, $ok ? $path : "{$path} (check directory permissions)");
        } catch (\Throwable $e) {
            return self::row($label, false, $e->getMessage());
        }
    }

    /** @return list<string> */
    public static function missingIndexes(\PDO $db): array
    {
        $expected = [
            'idx_donations_created_at',
            'idx_donations_status',
            'idx_donations_subscription',
            'idx_donations_livemode_created_at',
            'idx_donations_livemode_status',
            'idx_page_views_created_at',
        ];
        $missing = [];
        foreach ($expected as $name) {
            try {
                $stmt = $db->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :n LIMIT 1"
                );
                $stmt->execute([':n' => $name]);
                if ($stmt->fetchColumn() === false) {
                    $missing[] = $name;
                }
            } catch (\Throwable $e) {
                // If sqlite_master itself is unreachable the DB is in a
                // worse state than a missing index; surface that up.
                $missing[] = $name;
            }
        }
        return $missing;
    }

    private static function tableExists(\PDO $db, string $name): bool
    {
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1"
            );
            $stmt->execute([':n' => $name]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{label:string,ok:bool,detail:?string}
     */
    private static function row(string $label, bool $ok, ?string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }
}
