<?php
declare(strict_types=1);

namespace NDASA\Payment;

final class AmountValidator
{
    public function __construct(
        private readonly int $minCents,
        private readonly int $maxCents,
    ) {
        if ($this->minCents < 1 || $this->maxCents < $this->minCents) {
            throw new \InvalidArgumentException('Invalid amount bounds.');
        }
    }

    /**
     * Parse and validate a user-supplied amount in dollars to integer cents.
     *
     * @throws \InvalidArgumentException when the input fails validation
     */
    public function toCents(string $raw): int
    {
        $raw = trim($raw);
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw)) {
            throw new \InvalidArgumentException('Invalid amount format.');
        }
        $cents = (int) round(((float) $raw) * 100);
        if ($cents < $this->minCents || $cents > $this->maxCents) {
            throw new \InvalidArgumentException('Amount out of allowed range.');
        }
        return $cents;
    }
}
