<?php
declare(strict_types=1);

namespace NDASA\Tests\Http;

use NDASA\Http\RateLimiter;
use NDASA\Tests\Support\DatabaseTestCase;

final class RateLimiterTest extends DatabaseTestCase
{
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new RateLimiter($this->db);
    }

    public function test_first_request_is_allowed(): void
    {
        $this->assertTrue($this->limiter->allow('key1', 3, 60));
    }

    public function test_allows_up_to_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->limiter->allow('k', 5, 60), "request {$i} should be allowed");
        }
    }

    public function test_rejects_beyond_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->allow('k', 5, 60);
        }
        $this->assertFalse($this->limiter->allow('k', 5, 60));
        $this->assertFalse($this->limiter->allow('k', 5, 60));
    }

    public function test_separate_keys_are_independent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->allow('ip_1', 5, 60);
        }
        // ip_1 is over budget; ip_2 is untouched.
        $this->assertFalse($this->limiter->allow('ip_1', 5, 60));
        $this->assertTrue( $this->limiter->allow('ip_2', 5, 60));
    }

    public function test_window_rollover_resets_count(): void
    {
        // Consume the budget, then rewind window_start so the next call
        // treats it as outside the window.
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->allow('k', 5, 60);
        }
        $this->assertFalse($this->limiter->allow('k', 5, 60));

        // Simulate an aged window by pushing window_start into the past.
        $this->db->exec('UPDATE rate_limit SET window_start = 0 WHERE key = \'k\'');
        $this->assertTrue($this->limiter->allow('k', 5, 60));
    }

    public function test_creates_row_on_first_hit(): void
    {
        $this->limiter->allow('new_key', 10, 60);
        $row = $this->db->query("SELECT * FROM rate_limit WHERE key = 'new_key'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['count']);
    }
}
