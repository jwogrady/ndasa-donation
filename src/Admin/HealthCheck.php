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
     * @return array<string, list<array{label:string,ok:bool,detail:?string}>>
     */
    public static function all(): array
    {
        return [
            'Database'      => self::databaseChecks(),
            'Environment'   => self::environmentChecks(),
            'Configuration' => self::configurationChecks(),
        ];
    }

    /** @return list<array{label:string,ok:bool,detail:?string}> */
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
            : ['idx_donations_created_at', 'idx_page_views_created_at'];
        $out[] = self::row(
            'Database indexes',
            $missingIndexes === [],
            $missingIndexes === [] ? null : 'Missing: ' . implode(', ', $missingIndexes),
        );

        return $out;
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
        // Must stay in sync with the fail-closed check in config/app.php.
        $required = [
            'STRIPE_SECRET_KEY',
            'STRIPE_WEBHOOK_SECRET',
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
        $expected = ['idx_donations_created_at', 'idx_page_views_created_at'];
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
