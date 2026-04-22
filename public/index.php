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
        $method === 'GET'  && $path === '/admin'              => render_admin_dashboard(),
        $method === 'GET'  && $path === '/admin/config'       => render_admin_config(),
        $method === 'POST' && $path === '/admin/config'       => handle_admin_config(),
        $method === 'POST' && $path === '/admin/stripe-mode'  => handle_admin_stripe_mode(),
        $method === 'GET'  && $path === '/admin/export'       => handle_admin_export(),
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
        'MAIL_FROM',
        'MAIL_FROM_NAME',
        'MAIL_BCC_INTERNAL',
        'SMTP_HOST',
        'SMTP_PORT',
        'SMTP_ENCRYPTION',
        'SMTP_USERNAME',
        'SMTP_PASSWORD',
        'DONATION_MIN_CENTS',
        'DONATION_MAX_CENTS',
        'TRUSTED_PROXIES',
    ];
}

/**
 * Env vars the admin panel treats as mandatory for the request-time admin
 * flows. Must stay in sync with the fail-closed check in config/app.php.
 *
 * Stripe key/webhook pairs are intentionally excluded: bootstrap synthesizes
 * $_ENV['STRIPE_SECRET_KEY'] / $_ENV['STRIPE_WEBHOOK_SECRET'] from the mode-
 * specific pair (STRIPE_LIVE_* / STRIPE_TEST_*) at request time, so those two
 * keys are always populated by the time the admin handler runs — the presence
 * check here would always pass and mislead the operator. Bootstrap already
 * fails closed if the selected mode's credentials are missing; the mode panel
 * surfaces live-ready / test-ready explicitly.
 *
 * @return list<string>
 */
function admin_required_keys(): array
{
    return [
        'APP_URL',
        'DB_PATH',
        'MAIL_FROM',
        'MAIL_BCC_INTERNAL',
    ];
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

/**
 * Per-field sanity check for admin-config submissions. Returns a user-facing
 * error message when invalid, or null when the value is acceptable. Only
 * called for non-empty values; presence is enforced separately.
 */
function admin_validate_field(string $key, string $value): ?string
{
    switch ($key) {
        case 'APP_URL':
            $parts = parse_url($value);
            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                return 'APP_URL must be an absolute URL (e.g. https://example.org/donation).';
            }
            if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return 'APP_URL must use http or https.';
            }
            return null;

        case 'MAIL_FROM':
        case 'MAIL_BCC_INTERNAL':
            return filter_var($value, FILTER_VALIDATE_EMAIL)
                ? null
                : "{$key} must be a valid email address.";

        case 'SMTP_PORT':
            if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                return 'SMTP_PORT must be an integer between 1 and 65535.';
            }
            return null;

        case 'SMTP_ENCRYPTION':
            return in_array(strtolower($value), ['tls', 'ssl'], true)
                ? null
                : 'SMTP_ENCRYPTION must be either "tls" or "ssl".';

        case 'DONATION_MIN_CENTS':
        case 'DONATION_MAX_CENTS':
            if (!ctype_digit($value) || (int) $value < 1) {
                return "{$key} must be a positive integer (amount in cents).";
            }
            return null;

        default:
            return null;
    }
}

function render_admin_dashboard(?string $flashOk = null, ?string $flashErr = null): void
{
    // Some dashboards can still render even if the DB is unreachable — we
    // want the health panel to say so rather than throwing a 500.
    $metrics = null;
    $pageViews = $donationCount = $donorCount = $totalCents = 0;
    $conversionPct = 0.0;
    $recent = [];
    $stripeMode = defined('NDASA_STRIPE_MODE') ? NDASA_STRIPE_MODE : AppConfig::MODE_LIVE;

    try {
        $db = Database::connection();
        $metrics = new AdminMetrics($db);
        $pageViews     = $metrics->pageViewCount();
        $donationCount = $metrics->donationCount();
        $donorCount    = $metrics->donorCount();
        $totalCents    = $metrics->totalDonationCents();
        $conversionPct = $metrics->conversionRatePercent();
        $recent        = $metrics->recentDonations(10);
    } catch (\Throwable $e) {
        error_log('Admin dashboard metrics unavailable: ' . $e->getMessage());
    }

    // Target mode's credentials must be present before the toggle is offered,
    // or the flip would break the donor form on the next request.
    $testReady = AppConfig::resolveStripeCredentials(AppConfig::MODE_TEST, $_ENV) !== null;
    $liveReady = AppConfig::resolveStripeCredentials(AppConfig::MODE_LIVE, $_ENV) !== null;

    $missingRequired = admin_missing_required();
    // One probe populates both the grouped panel and the missing-index banner.
    $healthAll       = AdminHealthCheck::all();
    $health          = $healthAll['groups'];
    $missingIndexes  = $healthAll['missing_indexes'];
    $appVersion      = AdminVersion::current();
    $csrf            = Csrf::token();

    $auditEntries = [];
    try {
        $auditEntries = (new AuditLog(Database::connection()))->recent(20);
    } catch (\Throwable $e) {
        error_log('Audit read failed: ' . $e->getMessage());
    }

    require __DIR__ . '/../templates/admin/dashboard.php';
}

function handle_admin_stripe_mode(): void
{
    $token = $_POST[Csrf::FIELD] ?? null;
    if (!is_string($token) || !Csrf::validate($token)) {
        http_response_code(400);
        render_admin_dashboard(flashErr: 'Session expired. Please try again.');
        return;
    }

    $target = $_POST['mode'] ?? '';
    if ($target !== AppConfig::MODE_LIVE && $target !== AppConfig::MODE_TEST) {
        http_response_code(400);
        render_admin_dashboard(flashErr: 'Invalid mode.');
        return;
    }

    // Refuse the flip if the target mode has no credentials in .env.
    if (AppConfig::resolveStripeCredentials($target, $_ENV) === null) {
        $msg = $target === AppConfig::MODE_TEST
            ? 'Test mode is not configured: set STRIPE_TEST_SECRET_KEY and STRIPE_TEST_WEBHOOK_SECRET in .env.'
            : 'Live mode is not configured: set STRIPE_LIVE_SECRET_KEY and STRIPE_LIVE_WEBHOOK_SECRET in .env.';
        render_admin_dashboard(flashErr: $msg);
        return;
    }

    try {
        $db = Database::connection();
        $cfg = new AppConfig($db);
        $previous = $cfg->stripeMode();
        $cfg->set(AppConfig::STRIPE_MODE, $target);
        $actor = log_safe((string) ($_SERVER['PHP_AUTH_USER'] ?? '?'));
        error_log("NDASA: stripe_mode {$previous} -> {$target} by admin '{$actor}'");
        (new AuditLog($db))->record($actor, 'stripe_mode', "{$previous} -> {$target}");
    } catch (\Throwable $e) {
        error_log('Stripe mode toggle failed: ' . $e->getMessage());
        render_admin_dashboard(flashErr: 'Could not save the mode change: ' . $e->getMessage());
        return;
    }

    $label = $target === AppConfig::MODE_TEST ? 'TEST' : 'LIVE';
    render_admin_dashboard(flashOk: "Stripe mode is now {$label}. New checkouts will use {$label} credentials.");
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
        'MAIL_FROM'             => 'Address that staff notifications are sent from. Must be a mailbox the SMTP account is allowed to send as.',
        'MAIL_FROM_NAME'        => 'Display name on outgoing staff notifications. Optional; defaults to "NDASA Foundation".',
        'MAIL_BCC_INTERNAL'     => 'Address that receives a notification email for each completed donation.',
        'SMTP_HOST'             => 'SMTP server hostname (e.g. secure.emailsrvr.com).',
        'SMTP_PORT'             => 'SMTP port. 587 for STARTTLS, 465 for implicit TLS.',
        'SMTP_ENCRYPTION'       => 'Either "tls" (STARTTLS on 587) or "ssl" (implicit TLS on 465).',
        'SMTP_USERNAME'         => 'SMTP authentication username.',
        'SMTP_PASSWORD'         => 'SMTP authentication password. Stored plaintext in .env; protect the file with chmod 600.',
        'DONATION_MIN_CENTS'    => 'Minimum accepted donation amount in cents. Default 1000 ($10).',
        'DONATION_MAX_CENTS'    => 'Maximum accepted donation amount in cents. Default 1000000 ($10,000).',
        'TRUSTED_PROXIES'       => 'Comma-separated IPs or CIDRs of reverse proxies whose X-Forwarded-For may be trusted. Leave empty if the app is directly connected. Never use a wildcard.',
    ];

    $csrf            = Csrf::token();
    $missingRequired = admin_missing_required();
    $appVersion      = AdminVersion::current();
    $requiredKeys    = array_flip(admin_required_keys());

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

    $fields   = admin_editable_keys();
    $required = array_flip(admin_required_keys());
    $updates  = [];

    foreach ($fields as $k) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if ($v === '') {
            if (isset($required[$k])) {
                render_admin_config(flashErr: "{$k} cannot be empty.");
                return;
            }
            // Optional field left blank — write empty so the key round-trips
            // and any previous value is cleared.
            $updates[$k] = '';
            continue;
        }
        if (preg_match('/[\r\n]/', $v)) {
            render_admin_config(flashErr: "{$k} contains an invalid character.");
            return;
        }
        if (($err = admin_validate_field($k, $v)) !== null) {
            render_admin_config(flashErr: $err);
            return;
        }
        $updates[$k] = $v;
    }

    // Donation bounds sanity: min must be strictly less than max. Compare the
    // resolved post-save values, falling back to current env for any field
    // that was left blank this submission.
    $min = (int) ($updates['DONATION_MIN_CENTS'] !== '' ? $updates['DONATION_MIN_CENTS'] : ($_ENV['DONATION_MIN_CENTS'] ?? 1000));
    $max = (int) ($updates['DONATION_MAX_CENTS'] !== '' ? $updates['DONATION_MAX_CENTS'] : ($_ENV['DONATION_MAX_CENTS'] ?? 1_000_000));
    if ($min >= $max) {
        render_admin_config(flashErr: 'DONATION_MIN_CENTS must be less than DONATION_MAX_CENTS.');
        return;
    }

    $envPath = dirname(__DIR__) . '/.env';
    $envFile = new EnvFile($envPath);

    // Compute which keys actually changed so the audit log is useful rather
    // than "every key, every save". Compare against the on-disk file (not
    // $_ENV), so a field the operator leaves blank that was previously set
    // shows up as a change.
    $previous = $envFile->read();
    $changed = [];
    foreach ($updates as $k => $v) {
        $before = (string) ($previous[$k] ?? '');
        if ($before !== $v) {
            $changed[] = $k;
        }
    }

    try {
        $envFile->update($updates);
    } catch (\Throwable $e) {
        error_log('Admin config write failed: ' . $e->getMessage());
        render_admin_config(flashErr: 'Could not save changes: ' . $e->getMessage());
        return;
    }

    if ($changed !== []) {
        $actor = log_safe((string) ($_SERVER['PHP_AUTH_USER'] ?? '?'));
        // Record which keys changed, never the values — secrets must not
        // leak into the audit log.
        (new AuditLog(Database::connection()))->record(
            $actor,
            'config_save',
            'changed: ' . implode(', ', $changed),
        );
    }

    render_admin_config(flashOk: 'Saved. A PHP-FPM reload may be required for changes to take effect.');
}

/**
 * @param array<string, mixed> $post
 * @return array{fname:string,lname:string,email:string,amount:string,cover_fees:bool,dedication:string,email_optin:bool}
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

    try {
        $rows = (new AdminMetrics(Database::connection()))->donationsInRange($fromTs, $toTs);
    } catch (\Throwable $e) {
        error_log('CSV export failed: ' . $e->getMessage());
        http_response_code(500);
        render_error('Could not build the export.');
        return;
    }

    $suffix = ($fromRaw !== '' || $toRaw !== '')
        ? '_' . ($fromRaw ?: 'all') . '_' . ($toRaw ?: 'all')
        : '_' . date('Ymd');
    $filename = 'ndasa-donations' . $suffix . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'created_at', 'order_id', 'payment_intent_id', 'name', 'email',
        'amount', 'currency', 'status', 'dedication', 'email_optin', 'refunded_at',
    ]);
    foreach ($rows as $r) {
        $optin = $r['email_optin'];
        fputcsv($out, [
            gmdate('c', $r['created_at']),
            $r['order_id'],
            $r['payment_intent_id'] ?? '',
            $r['contact_name'] ?? '',
            $r['email'],
            number_format($r['amount_cents'] / 100, 2, '.', ''),
            strtoupper($r['currency']),
            $r['status'],
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

    try {
        $donation = (new AdminMetrics(Database::connection()))->findDonation($orderId);
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

    $stripeMode = defined('NDASA_STRIPE_MODE') ? NDASA_STRIPE_MODE : AppConfig::MODE_LIVE;
    $appVersion = AdminVersion::current();

    require __DIR__ . '/../templates/admin/donation.php';
}
