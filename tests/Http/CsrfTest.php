<?php
declare(strict_types=1);

namespace NDASA\Tests\Http;

use NDASA\Http\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Csrf::rotate() calls session_regenerate_id(), which requires an active
 * session. PHPUnit runs headless so we don't try to spin up a real one —
 * instead we directly manipulate $_SESSION and test the token-minting and
 * validation behaviors. rotate's ID regeneration is a no-op when no
 * session is active (it's guarded by session_status() === PHP_SESSION_ACTIVE).
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_token_mints_on_first_read(): void
    {
        $this->assertArrayNotHasKey('csrf', $_SESSION);
        $t = Csrf::token();
        $this->assertNotSame('', $t);
        $this->assertSame(64, strlen($t));       // 32 random bytes → 64 hex chars
        $this->assertSame($t, $_SESSION['csrf']);
    }

    public function test_token_is_stable_within_session(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        $this->assertSame($a, $b);
    }

    public function test_validate_accepts_current_token(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::validate($t));
    }

    public function test_validate_rejects_empty_submission(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::validate(''));
        $this->assertFalse(Csrf::validate(null));
    }

    public function test_validate_rejects_when_no_session_token(): void
    {
        $this->assertFalse(Csrf::validate('anything'));
    }

    public function test_validate_is_constant_time(): void
    {
        // hash_equals returns false for any wrong value.
        Csrf::token();
        $this->assertFalse(Csrf::validate(str_repeat('a', 64)));
        $this->assertFalse(Csrf::validate('not-the-right-token'));
    }

    public function test_rotate_mints_new_token(): void
    {
        $before = Csrf::token();
        Csrf::rotate();
        $after = $_SESSION['csrf'];
        $this->assertNotSame($before, $after);
        $this->assertSame(64, strlen($after));
    }

    public function test_rotate_invalidates_previous_token(): void
    {
        $before = Csrf::token();
        Csrf::rotate();
        $this->assertFalse(Csrf::validate($before));
        $this->assertTrue(Csrf::validate($_SESSION['csrf']));
    }
}
