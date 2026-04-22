<?php
declare(strict_types=1);

namespace NDASA\Payment;

final class FeeCalculator
{
    /** Stripe's US card percentage fee. Publicly exposed so the donation-page
     *  JS can read the same value and never drift from the server-side math. */
    public const PERCENT = 0.029;

    /** Stripe's US card fixed fee, in cents. Public for the same reason. */
    public const FIXED_CENTS = 30;

    /**
     * Gross up a donation so that after Stripe's 2.9% + 30c fee the foundation
     * nets the originally-intended amount. Returns the new charge in cents.
     */
    public function grossUp(int $cents): int
    {
        return (int) ceil(($cents + self::FIXED_CENTS) / (1 - self::PERCENT));
    }
}
