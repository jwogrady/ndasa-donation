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

require_once __DIR__ . '/../config/app.php';

use NDASA\Admin\Auth as AdminAuth;
use NDASA\Admin\EnvFile;
use NDASA\Admin\HealthCheck as AdminHealthCheck;
use NDASA\Admin\Metrics as AdminMetrics;
use NDASA\Admin\Version as AdminVersion;
use NDASA\Http\ClientIp;
use NDASA\Http\Csrf;
use NDASA\Http\RateLimiter;
use NDASA\Payment\AmountValidator;
use NDASA\Payment\DonationService;
use NDASA\Payment\FeeCalculator;
use NDASA\Support\Database;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Strip a leading "/index.php" if the web server didn't rewrite.
if (str_starts_with($path, '/index.php')) {
    $path = substr($path, strlen('/index.php')) ?: '/';
}

// Subpath deployments: strip the path prefix derived from APP_URL so the
// router below can compare against "/", "/checkout", "/success".
$basePath = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?? '', '/');
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

// Every /admin* request goes through one auth gate. On failure the gate
// terminates the request with 401 (or 500 if ADMIN_USER/ADMIN_PASS is unset).
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    AdminAuth::require($_SERVER, $_ENV);
}

try {
    match (true) {
        $method === 'GET'  && $path === '/'              => render_form(),
        $method === 'POST' && ($path === '/checkout' || $path === '/') => handle_checkout(),
        $method === 'GET'  && $path === '/success'       => render_success(),
        $method === 'GET'  && $path === '/admin'         => render_admin_dashboard(),
        $method === 'GET'  && $path === '/admin/config'  => render_admin_config(),
        $method === 'POST' && $path === '/admin/config'  => handle_admin_config(),
        default => not_found(),
    };
} catch (\Throwable $e) {
    error_log('Unhandled exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    render_error('An unexpected error occurred. Please try again shortly.');
}


function render_form(): void
{
    // Best-effort page-view tracking for the admin dashboard. Throttled per
    // session so a refresh-happy donor (or a bot that keeps a cookie jar)
    // only counts once per 30 seconds. A DB failure must not block the form.
    $now  = time();
    $last = (int) ($_SESSION['last_view_ts'] ?? 0);
    if ($now - $last > 30) {
        AdminMetrics::recordPageView(Database::connection());
        $_SESSION['last_view_ts'] = $now;
    }

    $csrf     = Csrf::token();
    $canceled = isset($_GET['canceled']);
    require __DIR__ . '/../templates/form.php';
}

function handle_checkout(): void
{
    $ip = ClientIp::resolve($_SERVER, (string) ($_ENV['TRUSTED_PROXIES'] ?? ''));

    $limiter = new RateLimiter(Database::connection());
    if (!$limiter->allow("checkout:{$ip}", limit: 5, windowSec: 60)) {
        http_response_code(429);
        render_error('Too many requests. Please wait a minute and try again.');
        return;
    }

    $token = $_POST[Csrf::FIELD] ?? null;
    if (!is_string($token) || !Csrf::validate($token)) {
        http_response_code(400);
        render_error('Your session has expired. Please reload the page and try again.');
        return;
    }

    try {
        $input = validate_donor_input($_POST);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        render_error($e->getMessage());
        return;
    }

    try {
        $cents = compute_charge_cents($input['amount'], $input['cover_fees']);
    } catch (\InvalidArgumentException) {
        http_response_code(422);
        render_error('Please enter a valid donation amount.');
        return;
    }

    $orderId = bin2hex(random_bytes(16));

    try {
        $session = (new DonationService(rtrim($_ENV['APP_URL'], '/')))->createCheckoutSession(
            $cents,
            $input['email'],
            $input['fname'] . ' ' . $input['lname'],
            $orderId,
        );
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Stripe checkout create failed: ' . $e->getMessage());
        http_response_code(502);
        render_error('We could not start the payment process. Please try again shortly.');
        return;
    }

    header('Location: ' . $session->url, true, 303);
}

function render_success(): void
{
    $sid = (string) ($_GET['sid'] ?? '');
    if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
        http_response_code(400);
        render_error('Invalid session reference.');
        return;
    }

    // Look up the session so we can show a truthful message for async payment
    // methods (ACH/Bacs) where 'paid' arrives later via webhook.
    $paymentStatus = 'unknown';
    try {
        $session = \Stripe\Checkout\Session::retrieve($sid);
        $paymentStatus = (string) ($session->payment_status ?? 'unknown');
    } catch (\Throwable $e) {
        error_log('Success page session lookup failed: ' . $e->getMessage());
    }

    require __DIR__ . '/../templates/success.php';
}

function render_error(string $message): void
{
    $error = $message;
    require __DIR__ . '/../templates/error.php';
}

function not_found(): void
{
    http_response_code(404);
    render_error('Not found.');
}

// ————————————————————————————————————————————————————————————————
//  Admin routes
//  Auth is applied upstream in the main dispatch block; these handlers
//  can assume the caller is authenticated.
// ————————————————————————————————————————————————————————————————

/** @return list<string> Editable .env keys exposed in the admin config form. */
function admin_editable_keys(): array
{
    return [
        'STRIPE_SECRET_KEY',
        'STRIPE_WEBHOOK_SECRET',
        'APP_URL',
        'MAIL_BCC_INTERNAL',
    ];
}

/** @return list<string> Env vars that must be present for the app to run. */
function admin_required_keys(): array
{
    return ['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'APP_URL'];
}

/** @return list<string> Required keys that are currently empty. */
function admin_missing_required(): array
{
    $missing = [];
    foreach (admin_required_keys() as $k) {
        if (empty($_ENV[$k])) {
            $missing[] = $k;
        }
    }
    return $missing;
}

function render_admin_dashboard(): void
{
    // Some dashboards can still render even if the DB is unreachable — we
    // want the health panel to say so rather than throwing a 500.
    $metrics = null;
    $pageViews = $donationCount = $donorCount = $totalCents = 0;
    $conversionPct = 0.0;
    $recent = [];
    $missingIndexes = ['idx_donations_created_at', 'idx_page_views_created_at'];

    try {
        $db = Database::connection();
        $metrics = new AdminMetrics($db);
        $pageViews     = $metrics->pageViewCount();
        $donationCount = $metrics->donationCount();
        $donorCount    = $metrics->donorCount();
        $totalCents    = $metrics->totalDonationCents();
        $conversionPct = $metrics->conversionRatePercent();
        $recent        = $metrics->recentDonations(10);
        $missingIndexes = AdminHealthCheck::missingIndexes($db);
    } catch (\Throwable $e) {
        error_log('Admin dashboard metrics unavailable: ' . $e->getMessage());
    }

    $missingRequired = admin_missing_required();
    $health          = AdminHealthCheck::all();
    $appVersion      = AdminVersion::current();

    require __DIR__ . '/../templates/admin/dashboard.php';
}

function render_admin_config(?string $flashOk = null, ?string $flashErr = null): void
{
    $fields = admin_editable_keys();

    $envPath = dirname(__DIR__) . '/.env';
    $stored  = (new EnvFile($envPath))->read();

    // Prefer live env (may reflect host-injected overrides) over file contents.
    $values = [];
    foreach ($fields as $k) {
        $values[$k] = (string) ($_ENV[$k] ?? $stored[$k] ?? '');
    }

    $descriptions = [
        'STRIPE_SECRET_KEY'     => 'Stripe live-mode secret key (sk_live_...). Test-mode keys start with sk_test_.',
        'STRIPE_WEBHOOK_SECRET' => 'Signing secret (whsec_...) from the webhook endpoint in the Stripe dashboard.',
        'APP_URL'               => 'Public origin of the donation app, including any subpath (e.g. https://ndasafoundation.org/donation).',
        'MAIL_BCC_INTERNAL'     => 'Address that receives a notification email for each completed donation.',
    ];

    $csrf            = Csrf::token();
    $missingRequired = admin_missing_required();
    $appVersion      = AdminVersion::current();

    require __DIR__ . '/../templates/admin/config.php';
}

function handle_admin_config(): void
{
    // Basic Auth does not prevent CSRF — browsers auto-send credentials.
    // Validate the same CSRF token the donation form uses.
    $token = $_POST[Csrf::FIELD] ?? null;
    if (!is_string($token) || !Csrf::validate($token)) {
        http_response_code(400);
        render_admin_config(flashErr: 'Your session expired or the request was invalid. Please try again.');
        return;
    }

    $fields  = admin_editable_keys();
    $updates = [];

    foreach ($fields as $k) {
        $v = (string) ($_POST[$k] ?? '');
        $v = trim($v);
        if ($v === '') {
            render_admin_config(flashErr: "{$k} cannot be empty.");
            return;
        }
        if (preg_match('/[\r\n]/', $v)) {
            render_admin_config(flashErr: "{$k} contains an invalid character.");
            return;
        }
        $updates[$k] = $v;
    }

    $envPath = dirname(__DIR__) . '/.env';
    try {
        (new EnvFile($envPath))->update($updates);
    } catch (\Throwable $e) {
        error_log('Admin config write failed: ' . $e->getMessage());
        render_admin_config(flashErr: 'Could not save changes: ' . $e->getMessage());
        return;
    }

    render_admin_config(flashOk: 'Saved. A PHP-FPM reload may be required for changes to take effect.');
}

/**
 * @param array<string, mixed> $post
 * @return array{fname:string,lname:string,email:string,phone:string,amount:string,cover_fees:bool}
 */
function validate_donor_input(array $post): array
{
    $fname = clean_name((string) ($post['fname'] ?? ''));
    $lname = clean_name((string) ($post['lname'] ?? ''));
    $email = filter_var(trim((string) ($post['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $phone = (string) preg_replace('/[^\d+\-\s()]/', '', (string) ($post['phone'] ?? ''));
    $cover = (($post['cover_fees'] ?? 'no') === 'yes');

    // Prefer the free-form amount; fall back to a whitelisted preset so the form
    // remains fully functional without JavaScript. Anything else is ignored
    // (the preset value is sent by the UI even for "Other").
    $amount = trim((string) ($post['amount'] ?? ''));
    if ($amount === '') {
        $preset = (string) ($post['preset'] ?? '');
        if (in_array($preset, ['25', '50', '100', '250', '500'], true)) {
            $amount = $preset;
        }
    }

    if ($fname === '' || $lname === '' || !is_string($email)) {
        throw new \InvalidArgumentException('Please provide your first name, last name, and a valid email address.');
    }

    // Defence in depth against header injection even though we don't use
    // mail() directly — these values flow into Stripe metadata and logs.
    foreach ([$fname, $lname, $email, $phone] as $v) {
        if (preg_match('/[\r\n]/', $v)) {
            throw new \InvalidArgumentException('Invalid input.');
        }
    }

    return [
        'fname'      => $fname,
        'lname'      => $lname,
        'email'      => $email,
        'phone'      => $phone,
        'amount'     => $amount,
        'cover_fees' => $cover,
    ];
}

function compute_charge_cents(string $amount, bool $coverFees): int
{
    $validator = new AmountValidator(
        (int) ($_ENV['DONATION_MIN_CENTS'] ?? 1000),
        (int) ($_ENV['DONATION_MAX_CENTS'] ?? 1_000_000),
    );
    $cents = $validator->toCents($amount);
    return $coverFees ? (new FeeCalculator())->grossUp($cents) : $cents;
}

function clean_name(string $v): string
{
    return mb_substr(trim($v), 0, 100);
}
