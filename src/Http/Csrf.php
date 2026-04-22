<?php
declare(strict_types=1);

namespace NDASA\Http;

final class Csrf
{
    public const FIELD = '_csrf';

    /**
     * Current session CSRF token, minting one lazily on first read.
     *
     * Same-browser replay is not a CSRF attack (the goal is cross-site); so
     * the token stays stable through a single session so honest retries,
     * back-button resubmits, and double-clicks succeed. Fresh form renders
     * should call rotate() to mint a new token for the next submission.
     */
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /**
     * Constant-time comparison. Does not mutate session state; rotation is a
     * render-time responsibility so a successful POST that then re-renders
     * the form can still serve a usable token.
     */
    public static function validate(?string $submitted): bool
    {
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($submitted) || $submitted === '' || $expected === '') {
            return false;
        }
        return hash_equals($expected, $submitted);
    }

    /**
     * Mint a fresh token. Call this on a fresh form render so a successful
     * POST does not reuse a token that is already known outside the session.
     */
    public static function rotate(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
