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

        // Indexes — both of these columns are filtered/ordered in dashboard
        // queries; without them, recent-donation lookups become full scans
        // once the tables have any real volume.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_donations_created_at ON donations(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_views_created_at ON page_views(created_at)');
    }
}
