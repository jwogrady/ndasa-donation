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

// Fail closed if required non-Stripe secrets are missing. Stripe key/webhook
// pairs are validated below after selecting the active mode (live/test).
$required = [
    'APP_URL',
    'DB_PATH',
];
foreach ($required as $key) {
    if (empty($_ENV[$key])) {
        http_response_code(500);
        error_log("NDASA: missing required env var {$key}");
        exit('Server misconfigured.');
    }
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

// ——— Stripe mode selection ———
// The donation app can run in "live" or "test" mode. Mode is stored in the
// app_config SQLite table so operators can flip it from the admin UI without
// editing .env or reloading PHP-FPM. Each mode reads its own key pair from
// .env (STRIPE_LIVE_SECRET_KEY / STRIPE_TEST_SECRET_KEY, etc.). Legacy
// STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET are accepted as fallbacks for
// live mode so older installs keep working.
try {
    $ndasaMode = (new \NDASA\Admin\AppConfig(\NDASA\Support\Database::connection()))->stripeMode();
} catch (\Throwable $e) {
    // DB not reachable — fall back to live. Missing DB is a bigger problem
    // that the rest of the app will surface on the first real query.
    error_log('NDASA: cannot read stripe_mode from app_config (' . $e->getMessage() . '); defaulting to live');
    $ndasaMode = \NDASA\Admin\AppConfig::MODE_LIVE;
}

$stripeCreds = \NDASA\Admin\AppConfig::resolveStripeCredentials($ndasaMode, $_ENV);
if ($stripeCreds === null) {
    http_response_code(500);
    error_log("NDASA: Stripe {$ndasaMode} mode selected but its key/webhook pair is missing from .env");
    exit('Server misconfigured.');
}
$stripeSecret  = $stripeCreds['secret'];
$stripeWebhook = $stripeCreds['webhook'];

// Re-populate $_ENV so downstream code (webhook.php, admin) reads the mode-
// appropriate secret without needing to know about the mode system.
$_ENV['STRIPE_SECRET_KEY']     = $stripeSecret;
$_ENV['STRIPE_WEBHOOK_SECRET'] = $stripeWebhook;
define('NDASA_STRIPE_MODE', $ndasaMode);

\Stripe\Stripe::setApiKey($stripeSecret);
// Pin the Stripe API version so webhook payload shapes are stable across
// SDK upgrades. Bump this deliberately when you've reviewed the diff.
\Stripe\Stripe::setApiVersion('2026-03-25.dahlia');
// App-version identifier flows through to Stripe's request `User-Agent` so
// the app info header and the admin footer can never drift.
\Stripe\Stripe::setAppInfo('NDASA-Donation', \NDASA\Admin\Version::current(), $_ENV['APP_URL']);

define('NDASA_BASE_PATH', rtrim(parse_url($_ENV['APP_URL'], PHP_URL_PATH) ?? '', '/'));

$isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';

// Webhook doesn't need browser security headers, sessions, or HTTPS redirect —
// it's a server-to-server POST from Stripe.
if (!defined('NDASA_SKIP_SESSION')) {
    // Per-request nonce for CSP. Templates read it via NDASA_CSP_NONCE.
    $cspNonce = base64_encode(random_bytes(16));
    define('NDASA_CSP_NONCE', $cspNonce);

    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self "https://checkout.stripe.com")');
    header('X-Frame-Options: DENY');
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'nonce-{$cspNonce}'; " .
        "style-src 'self' 'nonce-{$cspNonce}'; " .
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
