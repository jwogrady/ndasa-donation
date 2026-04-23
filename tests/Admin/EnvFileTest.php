<?php
declare(strict_types=1);

namespace NDASA\Tests\Admin;

use NDASA\Admin\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'ndasa-env-');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        @unlink($this->tmp . '.tmp');
    }

    public function test_read_empty_file(): void
    {
        $this->assertSame([], (new EnvFile($this->tmp))->read());
    }

    public function test_read_ignores_comments_and_blanks(): void
    {
        file_put_contents($this->tmp, <<<'ENV'
        # a comment
        FOO=bar

        # another
        BAZ=qux
        ENV);
        $env = (new EnvFile($this->tmp))->read();
        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $env);
    }

    public function test_read_unquotes_double_and_single_quoted_values(): void
    {
        file_put_contents($this->tmp, <<<'ENV'
        A="hello world"
        B='single quoted'
        C=bare
        ENV);
        $env = (new EnvFile($this->tmp))->read();
        $this->assertSame('hello world',    $env['A']);
        $this->assertSame('single quoted',  $env['B']);
        $this->assertSame('bare',           $env['C']);
    }

    public function test_update_preserves_comments_and_unrelated_keys(): void
    {
        file_put_contents($this->tmp, <<<'ENV'
        # Stripe configuration
        STRIPE_SECRET_KEY=sk_old
        # Something else
        APP_URL=https://example.com

        OTHER=unchanged
        ENV);
        (new EnvFile($this->tmp))->update(['STRIPE_SECRET_KEY' => 'sk_new']);

        $result = file_get_contents($this->tmp);
        $this->assertStringContainsString('# Stripe configuration', $result);
        $this->assertStringContainsString('# Something else',       $result);
        $this->assertStringContainsString('STRIPE_SECRET_KEY=sk_new', $result);
        $this->assertStringContainsString('OTHER=unchanged',         $result);
        $this->assertStringNotContainsString('sk_old', $result);
    }

    public function test_update_appends_new_keys(): void
    {
        file_put_contents($this->tmp, "FOO=bar\n");
        (new EnvFile($this->tmp))->update(['BAZ' => 'qux']);

        $env = (new EnvFile($this->tmp))->read();
        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $env);
    }

    public function test_update_rejects_crlf_injection(): void
    {
        file_put_contents($this->tmp, "OK=1\n");
        $this->expectException(\InvalidArgumentException::class);
        (new EnvFile($this->tmp))->update(['EVIL' => "hi\nADMIN_PASS=leak"]);
    }

    public function test_update_rejects_cr_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvFile($this->tmp))->update(['EVIL' => "hi\rADMIN=x"]);
    }

    public function test_update_rejects_bad_key_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvFile($this->tmp))->update(['lowercase' => 'x']);
    }

    public function test_update_quotes_values_with_spaces_or_specials(): void
    {
        // formatValue quotes values containing whitespace, `#`, or `=`;
        // other specials (like a bare `"`) pass through unquoted because
        // they don't break the parser's round-trip.
        file_put_contents($this->tmp, "");
        (new EnvFile($this->tmp))->update([
            'WITH_SPACE' => 'hello world',
            'WITH_HASH'  => 'a#b',
            'WITH_EQ'    => 'x=y',
            'BARE'       => 'simple',
        ]);
        $content = file_get_contents($this->tmp);
        $this->assertStringContainsString('WITH_SPACE="hello world"', $content);
        $this->assertStringContainsString('WITH_HASH="a#b"',          $content);
        $this->assertStringContainsString('WITH_EQ="x=y"',            $content);
        $this->assertStringContainsString('BARE=simple',              $content);
        // Round-trip: read-back must recover the original values.
        $env = (new EnvFile($this->tmp))->read();
        $this->assertSame('hello world', $env['WITH_SPACE']);
        $this->assertSame('a#b',         $env['WITH_HASH']);
        $this->assertSame('x=y',         $env['WITH_EQ']);
        $this->assertSame('simple',      $env['BARE']);
    }

    public function test_update_writes_atomically_via_rename(): void
    {
        // After a successful update, the .tmp sidecar must be gone.
        file_put_contents($this->tmp, "A=1\n");
        (new EnvFile($this->tmp))->update(['A' => '2']);
        $this->assertFileDoesNotExist($this->tmp . '.tmp');
        $this->assertSame('2', (new EnvFile($this->tmp))->read()['A']);
    }
}
