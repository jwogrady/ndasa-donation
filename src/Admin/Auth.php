<?php
/**
 * NDASA Donation Platform
 *
 * @package    NDASA\Donation
 * @author     William Cross
 * @author     John O'Grady <john@status26.com>
 * @copyright  2026 NDASA Foundation
 * @license    Proprietary - NDASA Foundation
 * @link       https://ndasafoundation.org/
 *
 * Maintained in honor of William Cross.
 */
declare(strict_types=1);

namespace NDASA\Admin;

/**
 * HTTP Basic Auth gate for all /admin* routes.
 *
 * Reads the expected credentials from $_ENV. Supports both the standard
 * PHP_AUTH_USER / PHP_AUTH_PW pair and the HTTP_AUTHORIZATION fallback
 * used by some PHP-FPM / LiteSpeed setups (including Nexcess managed WP)
 * where the platform does not populate PHP_AUTH_*.
 */
final class Auth
{
    public const REALM = 'NDASA Admin';

    /**
     * Require valid Basic Auth credentials or terminate the request with an
     * appropriate HTTP response. Returns on success only.
     *
     * @param array<string,mixed> $server Usually $_SERVER.
     * @param array<string,mixed> $env    Usually $_ENV.
     */
    public static function require(array $server, array $env): void
    {
        $expectedUser = (string) ($env['ADMIN_USER'] ?? '');
        $expectedPass = (string) ($env['ADMIN_PASS'] ?? '');

        if ($expectedUser === '' || $expectedPass === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            error_log('NDASA admin: ADMIN_USER / ADMIN_PASS not configured');
            echo "Admin is not configured. Set ADMIN_USER and ADMIN_PASS in .env.\n";
            exit;
        }

        [$user, $pass] = self::readCredentials($server);

        $userOk = hash_equals($expectedUser, $user);
        $passOk = hash_equals($expectedPass, $pass);

        if (!$userOk || !$passOk) {
            self::challenge();
        }
    }

    /**
     * Extract the submitted Basic credentials from $_SERVER, falling back to
     * parsing HTTP_AUTHORIZATION ourselves when PHP_AUTH_* is unset. Always
     * returns a two-element [user, pass] tuple; missing or malformed input
     * yields two empty strings so the subsequent hash_equals comparison
     * still runs in constant time and a garbage header is indistinguishable
     * (timing-wise) from an incorrect password.
     *
     * Hardened against these real-world oddities:
     * - PHP_AUTH_* unset by FastCGI / LiteSpeed.
     * - HTTP_AUTHORIZATION with surrounding whitespace from a proxy.
     * - Non-string values in $_SERVER (e.g. a pollution attempt).
     * - Empty scheme, unknown scheme, scheme with no token.
     * - base64_decode failures, decoded payload without ":", or a decoded
     *   payload whose first byte is ":" (empty username).
     * - Any unexpected throwable during parsing (defence in depth).
     *
     * @param array<string,mixed> $server
     * @return array{0:string,1:string}
     */
    private static function readCredentials(array $server): array
    {
        $user = is_string($server['PHP_AUTH_USER'] ?? null) ? (string) $server['PHP_AUTH_USER'] : '';
        $pass = is_string($server['PHP_AUTH_PW']   ?? null) ? (string) $server['PHP_AUTH_PW']   : '';

        if ($user !== '') {
            return [$user, $pass];
        }

        try {
            return self::parseAuthorizationHeader($server);
        } catch (\Throwable $e) {
            // Belt-and-braces: nothing in here should throw, but if the host
            // ever feeds us something truly unexpected we fail closed.
            error_log('Admin auth: header parse raised ' . $e::class . ': ' . $e->getMessage());
            return ['', ''];
        }
    }

    /**
     * @param array<string,mixed> $server
     * @return array{0:string,1:string}
     */
    private static function parseAuthorizationHeader(array $server): array
    {
        $candidates = [
            $server['HTTP_AUTHORIZATION'] ?? null,
            $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];
        $header = '';
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                $header = trim($c);
                break;
            }
        }
        if ($header === '') {
            return ['', ''];
        }

        // "Basic <token>" — exactly one token, optional trailing padding
        // but no other garbage. Case-insensitive scheme per RFC 7617.
        if (!preg_match('/^Basic[ \t]+([A-Za-z0-9+\/=]+)[ \t]*$/i', $header, $m)) {
            return ['', ''];
        }

        $decoded = base64_decode($m[1], strict: true);
        if (!is_string($decoded) || $decoded === '' || !str_contains($decoded, ':')) {
            return ['', ''];
        }

        [$u, $p] = explode(':', $decoded, 2);
        return [(string) $u, (string) $p];
    }

    private static function challenge(): never
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="' . self::REALM . '", charset="UTF-8"');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Authentication required.\n";
        exit;
    }
}
