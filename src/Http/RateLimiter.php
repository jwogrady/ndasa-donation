<?php
declare(strict_types=1);

namespace NDASA\Http;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Fixed-window limiter. Returns true if the request is allowed, false if
     * this key has exceeded `limit` within the current `windowSec` seconds.
     *
     * Nexcess managed WP ships SQLite 3.7.17 (no UPSERT, no RETURNING), so
     * this is a select-then-insert-or-update wrapped in a transaction.
     * SQLite's database-level write lock serializes concurrent writers.
     */
    public function allow(string $key, int $limit, int $windowSec): bool
    {
        $now    = time();
        $cutoff = $now - $windowSec;

        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare('SELECT count, window_start FROM rate_limit WHERE key = :k');
            $sel->execute([':k' => $key]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $ins = $this->db->prepare(
                    'INSERT INTO rate_limit (key, count, window_start) VALUES (:k, 1, :now)'
                );
                $ins->execute([':k' => $key, ':now' => $now]);
                $count = 1;
            } elseif ((int) $row['window_start'] < $cutoff) {
                $upd = $this->db->prepare(
                    'UPDATE rate_limit SET count = 1, window_start = :now WHERE key = :k'
                );
                $upd->execute([':k' => $key, ':now' => $now]);
                $count = 1;
            } else {
                $count = (int) $row['count'] + 1;
                $upd = $this->db->prepare('UPDATE rate_limit SET count = :c WHERE key = :k');
                $upd->execute([':k' => $key, ':c' => $count]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $count <= $limit;
    }
}
