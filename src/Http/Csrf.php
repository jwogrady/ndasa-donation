<?php
declare(strict_types=1);

namespace NDASA\Http;

final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function validate(?string $submitted): bool
    {
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($submitted) || $submitted === '' || $expected === '') {
            return false;
        }
        $ok = hash_equals($expected, $submitted);
        if ($ok) {
            // Rotate after successful use to prevent replay.
            unset($_SESSION['csrf']);
        }
        return $ok;
    }
}
