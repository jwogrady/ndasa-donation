<?php
declare(strict_types=1);

namespace NDASA\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = $_ENV['DB_PATH'] ?? '';
        if ($path === '') {
            throw new \RuntimeException('DB_PATH not configured.');
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o700, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS stripe_events (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL,
            received_at INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS donations (
            order_id TEXT PRIMARY KEY,
            payment_intent_id TEXT UNIQUE,
            amount_cents INTEGER NOT NULL,
            currency TEXT NOT NULL,
            email TEXT NOT NULL,
            contact_name TEXT,
            status TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            refunded_at INTEGER
        )');
        // Add new columns to existing installs. SQLite 3.7.17 has no
        // "ADD COLUMN IF NOT EXISTS", so we probe pragma_table_info first.
        $existingCols = [];
        foreach ($pdo->query("PRAGMA table_info(donations)") as $col) {
            $existingCols[(string) ($col['name'] ?? '')] = true;
        }
        if (!isset($existingCols['dedication'])) {
            $pdo->exec('ALTER TABLE donations ADD COLUMN dedication TEXT');
        }
        if (!isset($existingCols['email_optin'])) {
            // 0 = opted out, 1 = opted in. Nullable for rows that predate the
            // column (their consent is genuinely unknown, not "no").
            $pdo->exec('ALTER TABLE donations ADD COLUMN email_optin INTEGER');
        }
        if (!isset($existingCols['interval'])) {
            // 'month', 'year', or NULL for one-time. Every row in a recurring
            // series shares the same interval value; the signup row and each
            // subsequent invoice.paid create new rows linked by
            // stripe_subscription_id.
            $pdo->exec('ALTER TABLE donations ADD COLUMN "interval" TEXT');
        }
        if (!isset($existingCols['stripe_subscription_id'])) {
            $pdo->exec('ALTER TABLE donations ADD COLUMN stripe_subscription_id TEXT');
        }
        if (!isset($existingCols['stripe_customer_id'])) {
            // Captured on subscription signup so we can mint Customer Portal
            // sessions for the donor to cancel/manage from the success page.
            $pdo->exec('ALTER TABLE donations ADD COLUMN stripe_customer_id TEXT');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limit (
            key TEXT PRIMARY KEY,
            count INTEGER NOT NULL,
            window_start INTEGER NOT NULL
        )');
        // Unix-timestamp column for consistency with stripe_events and donations.
        $pdo->exec('CREATE TABLE IF NOT EXISTS page_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor TEXT NOT NULL,
            action TEXT NOT NULL,
            detail TEXT,
            created_at INTEGER NOT NULL
        )');

        // Indexes — both of these columns are filtered/ordered in dashboard
        // queries; without them, recent-donation lookups become full scans
        // once the tables have any real volume.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_donations_created_at ON donations(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_donations_status      ON donations(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_donations_subscription ON donations(stripe_subscription_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_views_created_at ON page_views(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_audit_created_at ON admin_audit(created_at)');
    }
}
