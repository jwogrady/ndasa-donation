<?php
declare(strict_types=1);

namespace NDASA\Tests\Payment;

use NDASA\Payment\FeeCalculator;
use PHPUnit\Framework\TestCase;

final class FeeCalculatorTest extends TestCase
{
    private FeeCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new FeeCalculator();
    }

    public function test_grossup_covers_100_dollar_donation(): void
    {
        // (10000 + 30) / 0.971 ≈ 10329.56 → ceil 10330.
        $this->assertSame(10330, $this->calc->grossUp(10000));
    }

    public function test_grossup_covers_minimum_donation(): void
    {
        // $10.00 minimum donation. (1000 + 30) / 0.971 = 1060.76 → ceil 1061.
        $this->assertSame(1061, $this->calc->grossUp(1000));
    }

    public function test_grossup_covers_maximum_donation(): void
    {
        // $10,000.00 max. (1_000_000 + 30) / 0.971 ≈ 1_029_897.01 → ceil 1029898.
        $this->assertSame(1029898, $this->calc->grossUp(1_000_000));
    }

    public function test_grossup_of_zero(): void
    {
        // Not a realistic donor input (validator rejects), but the math
        // should still be well-defined: 30c gross-up of zero is 31c (ceil
        // of 30 / 0.971). This documents the behavior so a future refactor
        // can't silently change it.
        $this->assertSame(31, $this->calc->grossUp(0));
    }

    public function test_grossup_always_rounds_up(): void
    {
        // (2500 + 30) / 0.971 ≈ 2605.56 — must ceil to 2606 so the foundation
        // is never short a cent.
        $this->assertSame(2606, $this->calc->grossUp(2500));
    }

    public function test_constants_are_stripe_us_card_fee_shape(): void
    {
        // Constants are public so the donor-form JS can read the same values.
        // Asserting they match the documented Stripe US card fee protects
        // against an accidental edit to one constant only.
        $this->assertSame(0.029, FeeCalculator::PERCENT);
        $this->assertSame(30, FeeCalculator::FIXED_CENTS);
    }
}
