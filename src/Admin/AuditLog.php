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
 * Append-only log of privileged admin actions (config saves, Stripe mode
 * toggles). Stored in the admin_audit SQLite table. Writes are best-effort:
 * a logging failure must never mask the underlying action's success or
 * failure, so the recorder swallows its own errors.
 */
final class AuditLog
{
    public function __construct(private readonly PDO $db) {}

    public function record(string $actor, string $action, ?string $detail = null): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO admin_audit (actor, action, detail, created_at) VALUES (?, ?, ?, ?)'
            )->execute([
                mb_substr($actor, 0, 100),
                mb_substr($action, 0, 100),
                $detail !== null ? mb_substr($detail, 0, 500) : null,
                time(),
            ]);
        } catch (\Throwable $e) {
            error_log('AuditLog write failed: ' . $e->getMessage());
        }
    }

    /**
     * Most recent audit rows, newest first. Capped at $limit.
     *
     * @return list<array{id:int,actor:string,action:string,detail:?string,created_at:int}>
     */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, actor, action, detail, created_at
             FROM admin_audit
             ORDER BY created_at DESC
             LIMIT :n'
        );
        $stmt->bindValue(':n', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'         => (int)    $r['id'],
                'actor'      => (string) $r['actor'],
                'action'     => (string) $r['action'],
                'detail'     => $r['detail'] !== null ? (string) $r['detail'] : null,
                'created_at' => (int)    $r['created_at'],
            ];
        }
        return $out;
    }
}
