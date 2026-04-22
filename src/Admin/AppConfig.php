<?php
/**
 * NDASA Donation Platform
 *
 * @package    NDASA\Donation
 * @author     John O'Grady <john@status26.com>
 * @copyright  2026 NDASA Foundation
 * @license    Proprietary - NDASA Foundation
 */
declare(strict_types=1);

namespace NDASA\Admin;

use PDO;

/**
 * Runtime admin-tweakable settings, persisted in SQLite's app_config table.
 * Distinct from .env: .env holds deploy-time secrets and cannot be safely
 * mutated from the running app on prod. app_config holds operator state
 * (e.g. current Stripe mode) that flips frequently without an FPM reload.
 */
final class AppConfig
{
    public const STRIPE_MODE     = 'stripe_mode';
    public const MODE_LIVE       = 'live';
    public const MODE_TEST       = 'test';

    public function __construct(private readonly PDO $db) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM app_config WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $now = time();
        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare('SELECT 1 FROM app_config WHERE key = :k');
            $sel->execute([':k' => $key]);
            if ($sel->fetchColumn()) {
                $upd = $this->db->prepare(
                    'UPDATE app_config SET value = :v, updated_at = :t WHERE key = :k'
                );
                $upd->execute([':k' => $key, ':v' => $value, ':t' => $now]);
            } else {
                $ins = $this->db->prepare(
                    'INSERT INTO app_config (key, value, updated_at) VALUES (:k, :v, :t)'
                );
                $ins->execute([':k' => $key, ':v' => $value, ':t' => $now]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function stripeMode(): string
    {
        $m = $this->get(self::STRIPE_MODE, self::MODE_LIVE);
        return $m === self::MODE_TEST ? self::MODE_TEST : self::MODE_LIVE;
    }

    public function isTestMode(): bool
    {
        return $this->stripeMode() === self::MODE_TEST;
    }
}
