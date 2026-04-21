<?php
declare(strict_types=1);

namespace NDASA\Payment;

final class FeeCalculator
{
    private const PERCENT = 0.029;
    private const FIXED_CENTS = 30;

    /**
     * Gross up a donation so that after Stripe's 2.9% + 30c fee the foundation
     * nets the originally-intended amount. Returns the new charge in cents.
     */
    public function grossUp(int $cents): int
    {
        return (int) ceil(($cents + self::FIXED_CENTS) / (1 - self::PERCENT));
    }
}
