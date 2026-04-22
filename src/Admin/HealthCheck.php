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
     * @return list<array{label:string,ok:bool,detail:?string}>
     */
    public static function all(): array
    {
        $out = [];

        // DB connection.
        $db = null;
        try {
            $db = Database::connection();
            $out[] = self::row('Database connection', true, null);
        } catch (\Throwable $e) {
            $out[] = self::row('Database connection', false, 'Unable to open: ' . $e->getMessage());
        }

        // Schema presence.
        foreach (['donations', 'page_views', 'stripe_events'] as $table) {
            $out[] = self::row(
                "Table: {$table}",
                $db !== null && self::tableExists($db, $table),
                null,
            );
        }

        // Indexes — if missing, show a dedicated optimisation warning on
        // the dashboard. Absence is not fatal; queries just get slower.
        $missingIndexes = $db !== null ? self::missingIndexes($db) : ['idx_donations_created_at', 'idx_page_views_created_at'];
        $out[] = self::row(
            'Database indexes',
            $missingIndexes === [],
            $missingIndexes === [] ? null : 'Missing: ' . implode(', ', $missingIndexes),
        );

        // Required env vars.
        foreach (['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'APP_URL'] as $key) {
            $out[] = self::row(
                "Env: {$key}",
                !empty($_ENV[$key]),
                null,
            );
        }

        return $out;
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
