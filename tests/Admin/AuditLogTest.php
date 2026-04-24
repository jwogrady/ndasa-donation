<?php
declare(strict_types=1);

namespace NDASA\Tests\Admin;

use NDASA\Admin\AuditLog;
use NDASA\Tests\Support\DatabaseTestCase;

final class AuditLogTest extends DatabaseTestCase
{
    private AuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new AuditLog($this->db);
    }

    public function test_record_inserts_row_with_timestamp(): void
    {
        $before = time();
        $this->log->record('admin', 'config.save', 'keys=APP_URL,DONATION_MIN_CENTS');

        $this->assertSame(1, $this->countRows('admin_audit'));
        $row = $this->db->query('SELECT * FROM admin_audit LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('admin',       $row['actor']);
        $this->assertSame('config.save', $row['action']);
        $this->assertSame('keys=APP_URL,DONATION_MIN_CENTS', $row['detail']);
        $this->assertGreaterThanOrEqual($before, (int) $row['created_at']);
    }

    public function test_record_allows_null_detail(): void
    {
        $this->log->record('admin', 'stripe_mode.flip');
        $row = $this->db->query('SELECT * FROM admin_audit LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['detail']);
    }

    public function test_record_truncates_overlong_values(): void
    {
        $longActor  = str_repeat('a', 200);
        $longAction = str_repeat('b', 200);
        $longDetail = str_repeat('c', 1000);
        $this->log->record($longActor, $longAction, $longDetail);

        $row = $this->db->query('SELECT * FROM admin_audit LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(100, mb_strlen($row['actor']));
        $this->assertSame(100, mb_strlen($row['action']));
        $this->assertSame(500, mb_strlen($row['detail']));
    }

    public function test_recent_returns_newest_first_capped_at_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->log->record('a', 'act', "detail-{$i}");
            // Spread the writes across distinct created_at values so the
            // DESC ordering is deterministic.
            usleep(1000); // 1ms — well under a test's budget
        }
        $rows = $this->log->recent(3);
        $this->assertCount(3, $rows);
        $this->assertSame('detail-4', $rows[0]['detail']); // newest first
    }

    public function test_recent_clamps_limit_to_safe_range(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->log->record('a', 'act');
        }
        $this->assertCount(3, $this->log->recent(9999));
        $this->assertCount(1, $this->log->recent(1));
        $this->assertCount(1, $this->log->recent(0));  // clamped to 1
        $this->assertCount(1, $this->log->recent(-5)); // clamped to 1
    }

    public function test_recent_shape_is_typed(): void
    {
        $this->log->record('jane', 'delete', 'x');
        $row = $this->log->recent(1)[0];

        $this->assertIsInt($row['id']);
        $this->assertSame('jane',   $row['actor']);
        $this->assertSame('delete', $row['action']);
        $this->assertSame('x',      $row['detail']);
        $this->assertIsInt($row['created_at']);
    }

    public function test_record_swallows_db_error(): void
    {
        // Drop the table to simulate a catastrophic DB state. record() logs
        // and returns without propagating — auditing must never break the
        // underlying action it was supposed to audit.
        $this->db->exec('DROP TABLE admin_audit');
        $this->log->record('a', 'b', 'c'); // should not throw
        $this->addToAssertionCount(1);
    }
}
