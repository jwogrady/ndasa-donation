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

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);

// Load .env if present. In prod, secrets may be injected by the host instead.
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

// Fail closed if required secrets are missing.
$required = [
    'STRIPE_SECRET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'APP_URL',
    'DB_PATH',
    'MAIL_FROM',
    'MAIL_BCC_INTERNAL',
];
foreach ($required as $key) {
    if (empty($_ENV[$key])) {
        http_response_code(500);
        error_log("NDASA: missing required env var {$key}");
        exit('Server misconfigured.');
    }
}
// SMTP: accept either a pre-formed DSN or discrete components (host is sufficient).
if (empty($_ENV['SMTP_DSN']) && empty($_ENV['SMTP_HOST'])) {
    http_response_code(500);
    error_log('NDASA: SMTP not configured (set SMTP_DSN or SMTP_HOST/SMTP_PORT/SMTP_USERNAME/SMTP_PASSWORD)');
    exit('Server misconfigured.');
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// Error reporting: never show to users, always log.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
$logFile = $root . '/storage/logs/app.log';
if (is_writable(dirname($logFile))) {
    ini_set('error_log', $logFile);
}
error_reporting(E_ALL);

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
// Pin the Stripe API version so webhook payload shapes are stable across
// SDK upgrades. Bump this deliberately when you've reviewed the diff.
\Stripe\Stripe::setApiVersion('2026-03-25.dahlia');
\Stripe\Stripe::setAppInfo('NDASA-Donation', '1.0.0', $_ENV['APP_URL']);

$isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';

// Webhook doesn't need browser security headers, sessions, or HTTPS redirect —
// it's a server-to-server POST from Stripe.
if (!defined('NDASA_SKIP_SESSION')) {
    // Per-request nonce for CSP. Templates read it via NDASA_CSP_NONCE.
    $cspNonce = base64_encode(random_bytes(16));
    define('NDASA_CSP_NONCE', $cspNonce);

    // Permissions-Policy: unquoted origins are the correct syntax per the spec.
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self https://checkout.stripe.com)');
    header('X-Frame-Options: DENY');
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'nonce-{$cspNonce}'; " .
        "style-src 'self'; " .
        "img-src 'self' data:; " .
        "connect-src 'self'; " .
        "form-action 'self' https://checkout.stripe.com; " .
        "base-uri 'self'; " .
        "object-src 'none'; " .
        "frame-ancestors 'none'"
    );

    if ($isProduction) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['HTTPS'] ?? '');
        $isHttps = $proto === 'https' || $proto === 'on';
        if (!$isHttps && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $host = $_SERVER['HTTP_HOST'] ?? parse_url($_ENV['APP_URL'], PHP_URL_HOST);
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }

    session_name($_ENV['SESSION_NAME'] ?? 'ndasa_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isProduction,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
