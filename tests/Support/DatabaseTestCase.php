<?php
declare(strict_types=1);

namespace NDASA\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real SQLite schema. Every test starts
 * with a fresh in-memory database populated with the same tables the
 * production migration creates, so schema tests are faithful but have no
 * filesystem side-effects and finish in microseconds.
 *
 * We DELIBERATELY duplicate the CREATE TABLE statements from
 * {@see \NDASA\Support\Database::migrate()} here rather than calling the
 * production migration, because Database::connection() is a static
 * singleton keyed by $_ENV['DB_PATH'] — coercing it into tests would
 * leak state across test methods. The cost of duplication is low (the
 * schema is stable and SQLite 3.7.17-safe statements are explicit), and
 * the isolation is worth it.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->applySchema();
    }

    private function applySchema(): void
    {
        $this->db->exec('CREATE TABLE stripe_events (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL,
            received_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE donations (
            order_id TEXT PRIMARY KEY,
            payment_intent_id TEXT UNIQUE,
            amount_cents INTEGER NOT NULL,
            currency TEXT NOT NULL,
            email TEXT NOT NULL,
            contact_name TEXT,
            status TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            refunded_at INTEGER,
            dedication TEXT,
            email_optin INTEGER,
            "interval" TEXT,
            stripe_subscription_id TEXT,
            stripe_customer_id TEXT,
            livemode INTEGER NOT NULL DEFAULT 1
        )');
        $this->db->exec('CREATE TABLE rate_limit (
            key TEXT PRIMARY KEY,
            count INTEGER NOT NULL,
            window_start INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE page_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE app_config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE admin_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor TEXT NOT NULL,
            action TEXT NOT NULL,
            detail TEXT,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX idx_donations_created_at ON donations(created_at)');
        $this->db->exec('CREATE INDEX idx_donations_status     ON donations(status)');
        $this->db->exec('CREATE INDEX idx_donations_subscription ON donations(stripe_subscription_id)');
        $this->db->exec('CREATE INDEX idx_donations_livemode_created_at ON donations(livemode, created_at)');
        $this->db->exec('CREATE INDEX idx_donations_livemode_status     ON donations(livemode, status)');
        $this->db->exec('CREATE INDEX idx_page_views_created_at ON page_views(created_at)');
        $this->db->exec('CREATE INDEX idx_admin_audit_created_at ON admin_audit(created_at)');
    }

    /** Convenience: fetch a single donation row by order_id (or null). */
    protected function findDonationRow(string $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM donations WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Convenience: count rows in any table. */
    protected function countRows(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
