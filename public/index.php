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

use NDASA\Admin\AppConfig;
use NDASA\Admin\AuditLog;
use NDASA\Admin\Auth as AdminAuth;
use NDASA\Admin\Diagnostics as AdminDiagnostics;
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
        $method === 'GET'  && $path === '/admin'              => render_admin_dashboard(),
        $method === 'GET'  && $path === '/admin/diagnostics'  => render_admin_diagnostics(),
        $method === 'POST' && $path === '/admin/stripe-mode'  => handle_admin_stripe_mode(),
        $method === 'GET'  && $path === '/admin/export'       => handle_admin_export(),
        $method === 'GET'  && $path === '/admin/transactions' => render_admin_transactions(),
        $method === 'GET'  && $path === '/admin/subscriptions' => render_admin_subscriptions(),
        $method === 'GET'  && str_starts_with($path, '/admin/subscriptions/') => render_admin_subscription(substr($path, strlen('/admin/subscriptions/'))),
        $method === 'GET'  && $path === '/admin/donors'       => render_admin_donors(),
        $method === 'GET'  && str_starts_with($path, '/admin/donors/') => render_admin_donor(substr($path, strlen('/admin/donors/'))),
        $method === 'GET'  && str_starts_with($path, '/admin/donations/') => render_admin_donation(substr($path, strlen('/admin/donations/'))),
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

    // Fresh form render: mint a new CSRF token so one session cannot reuse
    // an old token across unrelated attempts. Csrf::validate() no longer
    // rotates, so honest retries keep working — but every GET / does.
    Csrf::rotate();

    render_form_view();
}

/**
 * Render the donation form, optionally with sticky values and an error
 * summary. Used for both fresh GETs and validation-failure re-renders.
 *
 * @param array<string,mixed> $values Raw post data to re-hydrate into the form.
 * @param ?string             $error  Inline summary shown above the form.
 */
function render_form_view(array $values = [], ?string $error = null): void
{
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
        // Token missing or stale. Re-render the form — preserving what the
        // donor typed — with a fresh token so a single retry succeeds.
        http_response_code(400);
        Csrf::rotate();
        render_form_view(
            $_POST,
            'Something interrupted the last attempt. Your details are still here — just hit Donate again.',
        );
        return;
    }

    try {
        $input = validate_donor_input($_POST);
    } catch (\InvalidArgumentException $e) {
        // Sticky re-render so the donor can fix one field instead of
        // starting from a blank form.
        http_response_code(422);
        Csrf::rotate();
        render_form_view($_POST, $e->getMessage());
        return;
    }

    try {
        $cents = compute_charge_cents($input['amount'], $input['cover_fees']);
    } catch (\InvalidArgumentException) {
        http_response_code(422);
        Csrf::rotate();
        render_form_view($_POST, 'Please enter a valid donation amount.');
        return;
    }

    $orderId = bin2hex(random_bytes(16));

    try {
        $session = (new DonationService(rtrim($_ENV['APP_URL'], '/')))->createCheckoutSession(
            $cents,
            $input['email'],
            $input['fname'] . ' ' . $input['lname'],
            $orderId,
            $input['dedication'],
            $input['email_optin'],
            $input['interval'],
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
    // methods (ACH/Bacs) where 'paid' arrives later via webhook, and so we
    // know whether this was a subscription signup (mint a Customer Portal
    // link for cancel/manage).
    $paymentStatus = 'unknown';
    $interval      = null;   // 'month' | 'year' | null
    $portalUrl     = null;
    try {
        $session = \Stripe\Checkout\Session::retrieve($sid);
        $paymentStatus = (string) ($session->payment_status ?? 'unknown');
        $metaInterval  = (string) ($session->metadata->interval ?? 'once');
        if ($metaInterval === 'month' || $metaInterval === 'year') {
            $interval = $metaInterval;
        }
        $customerId = (string) ($session->customer ?? '');
        if ($interval !== null && $customerId !== '') {
            try {
                $portal = (new DonationService(rtrim($_ENV['APP_URL'], '/')))
                    ->createPortalSession($customerId, '/success?sid=' . urlencode($sid));
                $portalUrl = (string) $portal->url;
            } catch (\Throwable $e) {
                // Portal not enabled in the Stripe dashboard, or other failure.
                // Degrade gracefully — the receipt email still lets the donor
                // cancel by contacting us.
                error_log('Customer Portal session create failed: ' . $e->getMessage());
            }
        }
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

function render_admin_dashboard(): void
{
    // Fundraiser view: donation reporting only. System health, audit log,
    // mode toggle, and env-var presence all live on /admin/diagnostics.
    // Every template var starts at a safe zero so a DB outage still lets
    // the page render (with all zeros, which is honest).
    $pageViews = $donationCount = $donorCount = $totalCents = 0;
    $conversionPct = 0.0;
    $recent = [];
    $stripeMode    = current_stripe_mode();
    $lastWebhookAt = null;
    $lastWebhookLiveAt = null;
    $lastWebhookTestAt = null;
    $recurring     = ['subscriptions' => 0, 'monthly_cents' => 0];
    $repeatDonors  = [];
    $daily30       = [];
    $refundRate    = ['donations' => 0, 'refunded' => 0, 'rate_pct' => 0.0];

    try {
        $metrics = admin_metrics();
        $pageViews     = $metrics->pageViewCount();
        $donationCount = $metrics->donationCount();
        $donorCount    = $metrics->donorCount();
        $totalCents    = $metrics->totalDonationCents();
        $conversionPct = $metrics->conversionRatePercent();
        $recent        = $metrics->recentDonations(10);
        $lastWebhookAt     = $metrics->lastWebhookAt();
        $lastWebhookLiveAt = $metrics->lastWebhookAt(true);
        $lastWebhookTestAt = $metrics->lastWebhookAt(false);
        $recurring     = $metrics->activeRecurringCommitment();
        $repeatDonors  = $metrics->repeatDonors(10);
        $daily30       = $metrics->dailyTotalsLast(30);
        $refundRate    = $metrics->refundRateLast(30);
    } catch (\Throwable $e) {
        error_log('Admin dashboard metrics unavailable: ' . $e->getMessage());
    }

    $appVersion = AdminVersion::current();

    require __DIR__ . '/../templates/admin/dashboard.php';
}

function handle_admin_stripe_mode(): void
{
    // Post/Redirect/Get: the new mode is read from the DB at bootstrap
    // (config/app.php defines NDASA_STRIPE_MODE there). Re-rendering the
    // dashboard in-process would show stale status because the constant
    // was already frozen for this request. Redirect to /admin so the next
    // request re-bootstraps against the updated value.
    $token = $_POST[Csrf::FIELD] ?? null;
    if (!is_string($token) || !Csrf::validate($token)) {
        admin_flash_set(err: 'Session expired. Please try again.');
        admin_redirect_to_diagnostics();
        return;
    }

    $target = $_POST['mode'] ?? '';
    if ($target !== AppConfig::MODE_LIVE && $target !== AppConfig::MODE_TEST) {
        admin_flash_set(err: 'Invalid mode.');
        admin_redirect_to_diagnostics();
        return;
    }

    // Refuse the flip if the target mode has no credentials in .env.
    if (AppConfig::resolveStripeCredentials($target, $_ENV) === null) {
        $msg = $target === AppConfig::MODE_TEST
            ? 'Test mode is not configured: set STRIPE_TEST_SECRET_KEY and STRIPE_TEST_WEBHOOK_SECRET in .env.'
            : 'Live mode is not configured: set STRIPE_LIVE_SECRET_KEY and STRIPE_LIVE_WEBHOOK_SECRET in .env.';
        admin_flash_set(err: $msg);
        admin_redirect_to_diagnostics();
        return;
    }

    try {
        $db = Database::connection();
        $cfg = new AppConfig($db);
        $previous = $cfg->stripeMode();

        // No-op: submitting the form when already in the target mode (stale
        // page, double-click race, refresh-with-POST) must not pollute the
        // audit log with "live -> live" / "test -> test" rows. Short-circuit
        // with a neutral flash rather than mis-claim a flip happened.
        if ($previous === $target) {
            $label = $target === AppConfig::MODE_TEST ? 'TEST' : 'LIVE';
            admin_flash_set(ok: "Stripe mode is already {$label}; no change applied.");
            admin_redirect_to_diagnostics();
            return;
        }

        $cfg->set(AppConfig::STRIPE_MODE, $target);
        $actor = log_safe((string) ($_SERVER['PHP_AUTH_USER'] ?? '?'));
        error_log("NDASA: stripe_mode {$previous} -> {$target} by admin '{$actor}'");
        (new AuditLog($db))->record($actor, 'stripe_mode', "{$previous} -> {$target}");
    } catch (\Throwable $e) {
        error_log('Stripe mode toggle failed: ' . $e->getMessage());
        admin_flash_set(err: 'Could not save the mode change: ' . $e->getMessage());
        admin_redirect_to_diagnostics();
        return;
    }

    $label = $target === AppConfig::MODE_TEST ? 'TEST' : 'LIVE';
    admin_flash_set(ok: "Stripe mode is now {$label}. New checkouts will use {$label} credentials.");
    admin_redirect_to_diagnostics();
}

function admin_flash_set(?string $ok = null, ?string $err = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['admin_flash'] = ['ok' => $ok, 'err' => $err];
}

function admin_flash_pop(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['admin_flash'])) {
        return ['ok' => null, 'err' => null];
    }
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
    return [
        'ok'  => is_string($flash['ok'] ?? null) ? $flash['ok'] : null,
        'err' => is_string($flash['err'] ?? null) ? $flash['err'] : null,
    ];
}

function admin_redirect_to_diagnostics(): void
{
    // Mode toggle lives on /admin/diagnostics (the "geek view"); land the
    // operator back where they clicked so the flash shows next to the form.
    // NDASA_BASE_PATH accounts for subpath deploys (e.g. /donation) so the
    // redirect doesn't bounce through the parent site's router.
    header('Location: ' . NDASA_BASE_PATH . '/admin/diagnostics', true, 303);
}

function render_admin_diagnostics(): void
{
    // Read-only status page. No caching — admin is low-traffic and freshness
    // matters more than a second of latency. Individual tiles swallow their
    // own failures so a broken Stripe call never blanks the page.
    $diagnostics = AdminDiagnostics::gather();
    $appVersion  = AdminVersion::current();
    $stripeMode  = current_stripe_mode();
    $testReady   = AppConfig::resolveStripeCredentials(AppConfig::MODE_TEST, $_ENV) !== null;
    $liveReady   = AppConfig::resolveStripeCredentials(AppConfig::MODE_LIVE, $_ENV) !== null;
    $csrf        = Csrf::token();

    $flash    = admin_flash_pop();
    $flashOk  = $flash['ok'];
    $flashErr = $flash['err'];

    $auditEntries = [];
    try {
        $auditEntries = (new AuditLog(Database::connection()))->recent(20);
    } catch (\Throwable $e) {
        error_log('Audit read failed: ' . $e->getMessage());
    }

    require __DIR__ . '/../templates/admin/diagnostics.php';
}

/**
 * @param array<string, mixed> $post
 * @return array{fname:string,lname:string,email:string,amount:string,cover_fees:bool,dedication:string,email_optin:bool,interval:string}
 */
function validate_donor_input(array $post): array
{
    $fname = clean_name((string) ($post['fname'] ?? ''));
    $lname = clean_name((string) ($post['lname'] ?? ''));
    $email = filter_var(trim((string) ($post['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $cover = (($post['cover_fees'] ?? 'no') === 'yes');
    // Newsletter opt-in: a browser sends the checkbox value ('yes') only when
    // checked. Absent/empty = opted out. Pre-checked default lives in the
    // template; the server records whatever the donor actually submitted.
    $emailOptin = (($post['email_optin'] ?? '') === 'yes');
    // Frequency. Default 'once' for any unknown / missing value so a
    // malformed submission can never accidentally set up a subscription.
    $intervalIn = (string) ($post['interval'] ?? 'once');
    $interval = in_array($intervalIn, ['once', 'month', 'year'], true) ? $intervalIn : 'once';
    // Dedication is optional, capped at 200 chars; whitespace-only becomes empty.
    // Strip CR/LF rather than rejecting — it's a user-facing free-text field
    // where a stray newline shouldn't block the donation.
    $dedication = trim(preg_replace('/[\r\n]+/', ' ', (string) ($post['dedication'] ?? '')) ?? '');
    $dedication = mb_substr($dedication, 0, 200);

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
    foreach ([$fname, $lname, $email] as $v) {
        if (preg_match('/[\r\n]/', $v)) {
            throw new \InvalidArgumentException('Invalid input.');
        }
    }

    return [
        'fname'       => $fname,
        'lname'       => $lname,
        'email'       => $email,
        'amount'      => $amount,
        'cover_fees'  => $cover,
        'dedication'  => $dedication,
        'email_optin' => $emailOptin,
        'interval'    => $interval,
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

/**
 * Sanitize an untrusted string for interpolation into error_log lines. Strips
 * CR/LF so a crafted Authorization header (which controls $_SERVER['PHP_AUTH_USER'])
 * cannot inject fake log entries that confuse log aggregators or alerting.
 * Capped at 200 chars to keep runaway values from flooding syslog.
 */
function log_safe(string $v): string
{
    return mb_substr(str_replace(["\r", "\n", "\t"], ' ', $v), 0, 200);
}

/**
 * Stream a CSV export of donations in a [from, to) window. Date inputs are
 * YYYY-MM-DD (interpreted in APP_TIMEZONE, whole local days). Missing bounds
 * default to "all time". Emits text/csv with no-cache headers.
 */
function handle_admin_export(): void
{
    $tz = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');

    $fromRaw = (string) ($_GET['from'] ?? '');
    $toRaw   = (string) ($_GET['to']   ?? '');

    try {
        $fromTs = $fromRaw !== ''
            ? (new \DateTimeImmutable($fromRaw . ' 00:00:00', $tz))->getTimestamp()
            : 0;
        // Inclusive upper bound: add one day and use a half-open range below.
        $toTs = $toRaw !== ''
            ? (new \DateTimeImmutable($toRaw . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp()
            : PHP_INT_MAX;
    } catch (\Exception $e) {
        http_response_code(400);
        render_error('Invalid date. Use YYYY-MM-DD for from and to.');
        return;
    }

    $isLive = current_stripe_mode_is_live();

    try {
        $rows = admin_metrics()->donationsInRange($fromTs, $toTs);
    } catch (\Throwable $e) {
        error_log('CSV export failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not build the export.');
        return;
    }

    // Mode is baked into the filename so a test-mode export can never be
    // mistaken for a live-mode accounting report after download.
    $modeSlug = $isLive ? 'live' : 'test';
    $suffix = ($fromRaw !== '' || $toRaw !== '')
        ? '_' . ($fromRaw ?: 'all') . '_' . ($toRaw ?: 'all')
        : '_' . date('Ymd');
    $filename = 'ndasa-donations-' . $modeSlug . $suffix . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'created_at', 'order_id', 'payment_intent_id', 'subscription_id',
        'name', 'email', 'amount', 'currency', 'status', 'interval',
        'dedication', 'email_optin', 'refunded_at',
    ]);
    foreach ($rows as $r) {
        $optin = $r['email_optin'];
        fputcsv($out, [
            gmdate('c', $r['created_at']),
            $r['order_id'],
            $r['payment_intent_id'] ?? '',
            $r['stripe_subscription_id'] ?? '',
            $r['contact_name'] ?? '',
            $r['email'],
            number_format($r['amount_cents'] / 100, 2, '.', ''),
            strtoupper($r['currency']),
            $r['status'],
            $r['interval'] ?? 'once',
            $r['dedication'] ?? '',
            $optin === null ? '' : ($optin ? 'yes' : 'no'),
            $r['refunded_at'] !== null ? gmdate('c', $r['refunded_at']) : '',
        ]);
    }
    fclose($out);
}

/**
 * Admin donation detail page. Validates the order_id format before hitting
 * the DB so a malformed URL short-circuits to 404 rather than leaking the
 * query shape.
 */
function render_admin_donation(string $orderId): void
{
    // order_id is bin2hex(random_bytes(16)) = 32 lowercase hex chars.
    if (!preg_match('/^[a-f0-9]{32}$/', $orderId)) {
        not_found();
        return;
    }

    $stripeMode = current_stripe_mode();

    try {
        $donation = admin_metrics()->findDonation($orderId);
    } catch (\Throwable $e) {
        error_log('Donation detail lookup failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not load donation details.');
        return;
    }

    if ($donation === null) {
        not_found();
        return;
    }
    $appVersion = AdminVersion::current();

    require __DIR__ . '/../templates/admin/donation.php';
}

/**
 * Parse the "page size" query parameter and clamp to the allowed presets.
 * Lives here (not a helper elsewhere) because only the index pages use it.
 */
function admin_page_size(): int
{
    $raw = (int) ($_GET['per_page'] ?? 25);
    return in_array($raw, [25, 50, 100, 500], true) ? $raw : 25;
}

/** 1-based current page from `?page=N`, clamped to a sane minimum of 1. */
function admin_current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

/**
 * Resolve the three pagination inputs the index controllers share:
 * per-page size (clamped to the 25/50/100/500 presets), 1-based current
 * page, and the derived zero-based offset. Returned shape matches the
 * keys each controller already passes into its template or Metrics call.
 *
 * @return array{per_page:int,page:int,offset:int}
 */
function admin_pagination(): array
{
    $perPage = admin_page_size();
    $page    = admin_current_page();
    return [
        'per_page' => $perPage,
        'page'     => $page,
        'offset'   => ($page - 1) * $perPage,
    ];
}

/**
 * Parse YYYY-MM-DD from $_GET, returning a Unix timestamp at 00:00 in the
 * configured app timezone, or null if the input is missing or malformed.
 */
function admin_parse_date(string $key): ?int
{
    $raw = (string) ($_GET[$key] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    try {
        $tz = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        return (new \DateTimeImmutable($raw . ' 00:00:00', $tz))->getTimestamp();
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Like {@see admin_parse_date} but for the inclusive-to side of a date
 * range: returns the timestamp of the day *after* the parsed date so the
 * SQL query can do `created_at < :to` and still include the selected day.
 */
function admin_parse_date_to_inclusive(string $key): ?int
{
    if (admin_parse_date($key) === null) {
        return null;
    }
    try {
        $tz  = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $raw = (string) ($_GET[$key] ?? '');
        return (new \DateTimeImmutable($raw . ' 00:00:00', $tz))
            ->modify('+1 day')
            ->getTimestamp();
    } catch (\Throwable) {
        return null;
    }
}

/**
 * The admin's currently active Stripe mode as a plain string: `'live'` or
 * `'test'`. Templates that deep-link into the Stripe dashboard consume
 * this directly.
 */
function current_stripe_mode(): string
{
    return defined('NDASA_STRIPE_MODE') ? NDASA_STRIPE_MODE : AppConfig::MODE_LIVE;
}

/** True when the admin's currently active Stripe mode is "live". */
function current_stripe_mode_is_live(): bool
{
    return current_stripe_mode() === AppConfig::MODE_LIVE;
}

/** Convenience factory: a Metrics instance wired to the active Stripe mode. */
function admin_metrics(): AdminMetrics
{
    return new AdminMetrics(Database::connection(), current_stripe_mode_is_live());
}

function render_admin_transactions(): void
{
    $stripeMode = current_stripe_mode();
    ['per_page' => $perPage, 'page' => $page, 'offset' => $offset] = admin_pagination();

    $emailQ  = trim((string) ($_GET['email']  ?? ''));
    $status  = (string) ($_GET['status'] ?? '');
    $fromRaw = (string) ($_GET['from']  ?? '');
    $toRaw   = (string) ($_GET['to']    ?? '');

    $filters = [
        'email'   => $emailQ,
        'status'  => $status,
        'from_ts' => admin_parse_date('from'),
        'to_ts'   => admin_parse_date_to_inclusive('to'),
        'limit'   => $perPage,
        'offset'  => $offset,
    ];

    try {
        $metrics = admin_metrics();
        $rows  = $metrics->listTransactions($filters);
        $total = $metrics->countTransactions($filters);
    } catch (\Throwable $e) {
        error_log('Transactions index failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not load transactions.');
        return;
    }

    $appVersion = AdminVersion::current();
    require __DIR__ . '/../templates/admin/transactions.php';
}

function render_admin_subscriptions(): void
{
    $stripeMode = current_stripe_mode();
    ['per_page' => $perPage, 'page' => $page, 'offset' => $offset] = admin_pagination();

    try {
        $metrics = admin_metrics();
        $rows  = $metrics->listSubscriptions($perPage, $offset);
        $total = $metrics->countSubscriptions();
    } catch (\Throwable $e) {
        error_log('Subscriptions index failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not load subscriptions.');
        return;
    }

    $appVersion = AdminVersion::current();
    require __DIR__ . '/../templates/admin/subscriptions.php';
}

function render_admin_subscription(string $subId): void
{
    // Stripe subscription ids are "sub_" followed by 14+ alnum chars.
    if (!preg_match('/^sub_[A-Za-z0-9]+$/', $subId)) {
        not_found();
        return;
    }
    $stripeMode = current_stripe_mode();

    try {
        $invoices = admin_metrics()->subscriptionInvoices($subId);
    } catch (\Throwable $e) {
        error_log('Subscription detail lookup failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not load subscription details.');
        return;
    }

    if ($invoices === []) {
        not_found();
        return;
    }

    // Live status from Stripe. If unreachable, the page still renders and
    // the template shows a "status unknown" hint.
    $liveStatus  = null;
    $liveDetails = null;
    try {
        $sub = \Stripe\Subscription::retrieve($subId);
        $liveStatus = (string) ($sub->status ?? 'unknown');
        $liveDetails = [
            'current_period_end'   => (int) ($sub->current_period_end   ?? 0),
            'current_period_start' => (int) ($sub->current_period_start ?? 0),
            'cancel_at'            => $sub->cancel_at !== null ? (int) $sub->cancel_at : null,
            'cancel_at_period_end' => (bool) ($sub->cancel_at_period_end ?? false),
        ];
    } catch (\Throwable $e) {
        error_log('Stripe subscription retrieve failed for ' . $subId . ': ' . $e->getMessage());
    }

    $appVersion = AdminVersion::current();
    require __DIR__ . '/../templates/admin/subscription.php';
}

function render_admin_donors(): void
{
    $stripeMode = current_stripe_mode();
    ['per_page' => $perPage, 'page' => $page, 'offset' => $offset] = admin_pagination();

    try {
        $metrics = admin_metrics();
        $rows  = $metrics->listDonors($perPage, $offset);
        $total = $metrics->countDonors();
    } catch (\Throwable $e) {
        error_log('Donors index failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not load donors.');
        return;
    }

    $appVersion = AdminVersion::current();
    require __DIR__ . '/../templates/admin/donors.php';
}

function render_admin_donor(string $emailHash): void
{
    // SHA-256 hex is 64 lowercase chars. Format-check before DB hit so a
    // garbage URL never flows into the scan-and-compare loop.
    if (!preg_match('/^[a-f0-9]{64}$/', $emailHash)) {
        not_found();
        return;
    }
    $stripeMode = current_stripe_mode();

    try {
        $donor = admin_metrics()->findDonorByEmailHash($emailHash);
    } catch (\Throwable $e) {
        error_log('Donor detail lookup failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not load donor details.');
        return;
    }

    if ($donor === null) {
        not_found();
        return;
    }

    $receiptUrls = resolve_stripe_receipt_urls($donor['donations']);

    $appVersion = AdminVersion::current();
    require __DIR__ . '/../templates/admin/donor.php';
}

/**
 * For each donation that has a PaymentIntent id, ask Stripe for the hosted
 * receipt URL on the latest charge. One API call per PI; acceptable on a
 * per-donor detail page where the count is small.
 *
 * Lookups are independent — a single failure logs and is skipped; others
 * still populate. Returns a map of PI id → receipt URL.
 *
 * @param  list<array<string,mixed>> $donations
 * @return array<string,string>
 */
function resolve_stripe_receipt_urls(array $donations): array
{
    $out = [];
    foreach ($donations as $d) {
        $pi = $d['payment_intent_id'] ?? null;
        if (!is_string($pi) || $pi === '') {
            continue;
        }
        try {
            $intent = \Stripe\PaymentIntent::retrieve([
                'id'     => $pi,
                'expand' => ['latest_charge'],
            ]);
            $charge = $intent->latest_charge ?? null;
            $url    = $charge !== null ? (string) ($charge->receipt_url ?? '') : '';
            if ($url !== '') {
                $out[$pi] = $url;
            }
        } catch (\Throwable $e) {
            error_log('Stripe receipt lookup failed for PI ' . $pi . ': ' . $e->getMessage());
        }
    }
    return $out;
}
