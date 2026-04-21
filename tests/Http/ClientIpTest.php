<?php
declare(strict_types=1);

namespace NDASA\Tests\Http;

use NDASA\Http\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function test_returns_remote_when_no_trusted_proxies(): void
    {
        $ip = ClientIp::resolve(
            ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
            trustedCsv: '',
        );
        $this->assertSame('203.0.113.5', $ip);
    }

    public function test_ignores_xff_when_remote_is_not_trusted(): void
    {
        $ip = ClientIp::resolve(
            ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
            trustedCsv: '10.0.0.0/8',
        );
        $this->assertSame('203.0.113.5', $ip);
    }

    public function test_uses_xff_when_remote_is_trusted(): void
    {
        $ip = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'],
            trustedCsv: '10.0.0.0/8',
        );
        $this->assertSame('198.51.100.9', $ip);
    }

    public function test_skips_trusted_hops_to_find_real_client(): void
    {
        $ip = ClientIp::resolve(
            [
                'REMOTE_ADDR'          => '10.0.0.7',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.8, 10.0.0.7',
            ],
            trustedCsv: '10.0.0.0/8',
        );
        $this->assertSame('198.51.100.9', $ip);
    }

    public function test_exact_ip_match(): void
    {
        $ip = ClientIp::resolve(
            ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'],
            trustedCsv: '192.0.2.1',
        );
        $this->assertSame('198.51.100.9', $ip);
    }

    public function test_rejects_malformed_xff(): void
    {
        $ip = ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'],
            trustedCsv: '10.0.0.0/8',
        );
        $this->assertSame('10.0.0.7', $ip);
    }
}
