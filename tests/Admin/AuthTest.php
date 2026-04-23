<?php
declare(strict_types=1);

namespace NDASA\Tests\Admin;

use NDASA\Admin\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see \NDASA\Admin\Auth::require}. The method calls `exit` on
 * auth failure, which PHPUnit can't follow, so we test the underlying
 * credential parser via a small reflection harness. The parser is the
 * actually-interesting code: it handles FastCGI and LiteSpeed quirks
 * where PHP_AUTH_* may be unset and the raw `Authorization` header is
 * our only source of truth.
 */
final class AuthTest extends TestCase
{
    public function test_reads_php_auth_pair_when_present(): void
    {
        [$u, $p] = $this->parse(['PHP_AUTH_USER' => 'jane', 'PHP_AUTH_PW' => 'secret']);
        $this->assertSame(['jane', 'secret'], [$u, $p]);
    }

    public function test_reads_authorization_header_when_php_auth_absent(): void
    {
        $header = 'Basic ' . base64_encode('jane:secret');
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => $header]);
        $this->assertSame(['jane', 'secret'], [$u, $p]);
    }

    public function test_reads_redirect_authorization_fallback(): void
    {
        // Some Apache setups rewrite the header into REDIRECT_HTTP_AUTHORIZATION.
        $header = 'Basic ' . base64_encode('bob:hunter2');
        [$u, $p] = $this->parse(['REDIRECT_HTTP_AUTHORIZATION' => $header]);
        $this->assertSame(['bob', 'hunter2'], [$u, $p]);
    }

    public function test_accepts_case_insensitive_scheme(): void
    {
        $header = 'BaSiC ' . base64_encode('u:p');
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => $header]);
        $this->assertSame(['u', 'p'], [$u, $p]);
    }

    public function test_handles_surrounding_whitespace(): void
    {
        $header = "  Basic " . base64_encode('u:p') . "  \t";
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => $header]);
        $this->assertSame(['u', 'p'], [$u, $p]);
    }

    public function test_rejects_wrong_scheme(): void
    {
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => 'Bearer xyz.abc']);
        $this->assertSame(['', ''], [$u, $p]);
    }

    public function test_rejects_empty_header(): void
    {
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => '']);
        $this->assertSame(['', ''], [$u, $p]);
    }

    public function test_rejects_malformed_base64(): void
    {
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => 'Basic !@#$%']);
        $this->assertSame(['', ''], [$u, $p]);
    }

    public function test_rejects_base64_payload_without_colon(): void
    {
        // "noColon" decodes cleanly but has no delimiter — must be rejected,
        // otherwise constant-time comparison still runs against (decoded, '').
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('noColon')]);
        $this->assertSame(['', ''], [$u, $p]);
    }

    public function test_rejects_non_string_server_values(): void
    {
        // Simulates $_SERVER pollution; must not crash or coerce.
        [$u, $p] = $this->parse(['PHP_AUTH_USER' => ['array']]);
        $this->assertSame(['', ''], [$u, $p]);
    }

    public function test_password_may_contain_colon(): void
    {
        // explode(':', ..., 2) keeps the first delimiter; any subsequent
        // colons belong to the password. Real passwords do contain ':'.
        $header = 'Basic ' . base64_encode('u:pa:ss:word');
        [$u, $p] = $this->parse(['HTTP_AUTHORIZATION' => $header]);
        $this->assertSame(['u', 'pa:ss:word'], [$u, $p]);
    }

    // ───────────── harness ─────────────

    /**
     * @param array<string,mixed> $server
     * @return array{0:string,1:string}
     */
    private function parse(array $server): array
    {
        $rc = new \ReflectionClass(Auth::class);
        $method = $rc->getMethod('readCredentials');
        $method->setAccessible(true);
        return $method->invoke(null, $server);
    }
}
