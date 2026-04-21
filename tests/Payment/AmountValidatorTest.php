<?php
declare(strict_types=1);

namespace NDASA\Tests\Payment;

use NDASA\Payment\AmountValidator;
use PHPUnit\Framework\TestCase;

final class AmountValidatorTest extends TestCase
{
    private AmountValidator $v;

    protected function setUp(): void
    {
        $this->v = new AmountValidator(1000, 1_000_000);
    }

    public function test_accepts_simple_integer_dollars(): void
    {
        $this->assertSame(2500, $this->v->toCents('25'));
    }

    public function test_accepts_decimal_dollars(): void
    {
        $this->assertSame(1050, $this->v->toCents('10.50'));
    }

    public function test_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->toCents('-5');
    }

    public function test_rejects_below_min(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->toCents('9.99');
    }

    public function test_rejects_above_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->toCents('10001');
    }

    public function test_rejects_scientific_notation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->toCents('1e5');
    }

    public function test_rejects_alpha(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->toCents('abc');
    }

    public function test_rejects_too_many_decimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->v->toCents('10.123');
    }
}
