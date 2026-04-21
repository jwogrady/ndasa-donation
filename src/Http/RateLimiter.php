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
     * Uses SQLite's UPSERT so the common case is a single atomic statement —
     * no read-modify-write race under contention.
     */
    public function allow(string $key, int $limit, int $windowSec): bool
    {
        $now = time();
        $windowStart = $now - $windowSec;

        $stmt = $this->db->prepare(
            'INSERT INTO rate_limit (key, count, window_start) VALUES (:k, 1, :now)
             ON CONFLICT(key) DO UPDATE SET
                count        = CASE WHEN window_start < :cutoff THEN 1 ELSE count + 1 END,
                window_start = CASE WHEN window_start < :cutoff THEN :now ELSE window_start END
             RETURNING count'
        );
        $stmt->execute([':k' => $key, ':now' => $now, ':cutoff' => $windowStart]);
        $count = (int) $stmt->fetchColumn();

        return $count <= $limit;
    }
}
