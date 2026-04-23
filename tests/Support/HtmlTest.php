<?php
declare(strict_types=1);

namespace NDASA\Tests\Support;

use NDASA\Support\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function test_escapes_script_tag(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            Html::h('<script>alert(1)</script>')
        );
    }

    public function test_escapes_both_quote_flavors(): void
    {
        // ENT_QUOTES must be set so both " and ' escape, otherwise a
        // single-quoted HTML attribute value is injectable. ENT_HTML5
        // emits the named `&apos;` entity (HTML5) rather than `&#039;`.
        $this->assertSame(
            '&quot;a&quot;&amp;&apos;b&apos;',
            Html::h('"a"&\'b\'')
        );
    }

    public function test_coerces_non_strings(): void
    {
        $this->assertSame('42',    Html::h(42));
        $this->assertSame('3.14',  Html::h(3.14));
        $this->assertSame('',      Html::h(null));
        $this->assertSame('1',     Html::h(true));
        $this->assertSame('',      Html::h(false));
    }

    public function test_passes_through_harmless_text(): void
    {
        $this->assertSame(
            'Hello, World — this is fine.',
            Html::h('Hello, World — this is fine.')
        );
    }

    public function test_passes_through_utf8(): void
    {
        // UTF-8 must not be mangled by the escaper (ENT_HTML5 + UTF-8).
        $this->assertSame(
            'café 日本語',
            Html::h('café 日本語')
        );
    }
}
