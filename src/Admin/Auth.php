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
     * returns a two-element [user, pass] tuple; missing values are empty
     * strings so the subsequent hash_equals comparison runs in constant time.
     *
     * @param array<string,mixed> $server
     * @return array{0:string,1:string}
     */
    private static function readCredentials(array $server): array
    {
        $user = (string) ($server['PHP_AUTH_USER'] ?? '');
        $pass = (string) ($server['PHP_AUTH_PW'] ?? '');

        if ($user !== '') {
            return [$user, $pass];
        }

        // Some FastCGI setups strip PHP_AUTH_* but preserve Authorization.
        $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' || stripos($header, 'basic ') !== 0) {
            return ['', ''];
        }

        $decoded = base64_decode(substr($header, 6), strict: true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return ['', ''];
        }

        [$u, $p] = explode(':', $decoded, 2);
        return [$u, $p];
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
