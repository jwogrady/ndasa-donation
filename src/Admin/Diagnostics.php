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

use NDASA\Support\Database;
use PDO;
use Stripe\StripeClient;

/**
 * Read-only "geek view" status collector.
 *
 * Gathers app, PHP, SQLite, filesystem, log, env, Stripe-key, Stripe-account,
 * Stripe-endpoint, and webhook-heartbeat tiles in one call. Each tile is a
 * {label, status, value, detail} shape that the diagnostics template renders
 * with a colored left-border.
 *
 * Hits the Stripe API live each page load — this page is admin-gated and
 * low-traffic, so freshness beats caching. Failures are caught per-tile so
 * a broken Stripe call never takes out the page.
 */
final class Diagnostics
{
    private const STATUS_OK   = 'ok';
    private const STATUS_WARN = 'warn';
    private const STATUS_BAD  = 'bad';
    private const STATUS_GONE = 'gone';

    /** Webhook event types the app's handler actually dispatches. */
    private const REQUIRED_EVENT_TYPES = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'charge.refunded',
        'payment_intent.payment_failed',
        'invoice.paid',
        'invoice.payment_failed',
        'customer.subscription.deleted',
    ];

    /** PHP extensions the app depends on. */
    private const REQUIRED_EXTENSIONS = ['pdo_sqlite', 'curl', 'openssl', 'mbstring', 'json', 'filter'];

    /**
     * @return array{
     *   app:        list<array{label:string,status:string,value:string,detail:?string}>,
     *   php:        list<array{label:string,status:string,value:string,detail:?string}>,
     *   database:   list<array{label:string,status:string,value:string,detail:?string}>,
     *   filesystem: list<array{label:string,status:string,value:string,detail:?string}>,
     *   logs:       list<array{label:string,status:string,value:string,detail:?string}>,
     *   env:        list<array{label:string,status:string,value:string,detail:?string}>,
     *   stripe_live: list<array{label:string,status:string,value:string,detail:?string}>,
     *   stripe_test: list<array{label:string,status:string,value:string,detail:?string}>,
     *   legacy_keys: list<array{label:string,status:string,value:string,detail:?string}>,
     *   log_tail:   list<string>
     * }
     */
    public static function gather(): array
    {
        return [
            'app'         => self::appTiles(),
            'php'         => self::phpTiles(),
            'database'    => self::databaseTiles(),
            'filesystem'  => self::filesystemTiles(),
            'logs'        => self::logTiles(),
            'env'         => self::envTiles(),
            'stripe_live' => self::stripeModeTiles(AppConfig::MODE_LIVE),
            'stripe_test' => self::stripeModeTiles(AppConfig::MODE_TEST),
            'legacy_keys' => self::legacyStripeKeyTiles(),
            'log_tail'    => self::logTail(10),
        ];
    }

    // ────────── App ──────────
    private static function appTiles(): array
    {
        $mode = defined('NDASA_STRIPE_MODE') ? NDASA_STRIPE_MODE : AppConfig::MODE_LIVE;
        return [
            self::tile('App version',      self::STATUS_OK, Version::current()),
            self::tile('APP_URL',          self::STATUS_OK, (string) ($_ENV['APP_URL'] ?? '(unset)')),
            self::tile('APP_TIMEZONE',     self::STATUS_OK, (string) ($_ENV['APP_TIMEZONE'] ?? date_default_timezone_get())),
            self::tile('Stripe mode',      $mode === AppConfig::MODE_TEST ? self::STATUS_WARN : self::STATUS_OK, strtoupper($mode)),
            self::tile('Base path',        self::STATUS_OK, defined('NDASA_BASE_PATH') ? NDASA_BASE_PATH : '(unset)'),
        ];
    }

    // ────────── PHP ──────────
    private static function phpTiles(): array
    {
        $tiles = [
            self::tile('PHP version',       self::STATUS_OK, PHP_VERSION),
            self::tile('SAPI',              self::STATUS_OK, PHP_SAPI),
            self::tile('memory_limit',      self::STATUS_OK, (string) ini_get('memory_limit')),
            self::tile('post_max_size',     self::STATUS_OK, (string) ini_get('post_max_size')),
            self::tile('upload_max_filesize', self::STATUS_OK, (string) ini_get('upload_max_filesize')),
            self::tile('max_execution_time', self::STATUS_OK, (string) ini_get('max_execution_time') . 's'),
            self::tile('date.timezone',     self::STATUS_OK, (string) (ini_get('date.timezone') ?: '(unset)')),
        ];

        // display_errors must be off in production — a leak into a 500 page
        // would reveal stack traces with secrets. log_errors must be on so
        // webhook diagnostics are usable.
        $displayErrors = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
        $tiles[] = self::tile(
            'display_errors',
            $displayErrors ? self::STATUS_BAD : self::STATUS_OK,
            $displayErrors ? 'On' : 'Off',
            $displayErrors ? 'Should be Off in production.' : null,
        );
        $logErrors = filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN);
        $tiles[] = self::tile(
            'log_errors',
            $logErrors ? self::STATUS_OK : self::STATUS_BAD,
            $logErrors ? 'On' : 'Off',
            $logErrors ? null : 'Should be On so webhook errors are visible.',
        );

        // Required extensions loaded.
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        $tiles[] = self::tile(
            'Required extensions',
            $missing === [] ? self::STATUS_OK : self::STATUS_BAD,
            $missing === [] ? implode(', ', self::REQUIRED_EXTENSIONS) : 'missing: ' . implode(', ', $missing),
        );

        // Sessions — admin flash messages depend on this working.
        $tiles[] = self::tile('session.save_handler',  self::STATUS_OK, (string) ini_get('session.save_handler'));
        $cookieSecure = filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOLEAN);
        $tiles[] = self::tile(
            'session.cookie_secure',
            $cookieSecure ? self::STATUS_OK : self::STATUS_WARN,
            $cookieSecure ? 'On' : 'Off',
            $cookieSecure ? null : 'Admin cookies will ride over HTTP. Only safe for local dev.',
        );
        $tiles[] = self::tile('session.cookie_samesite', self::STATUS_OK, (string) (ini_get('session.cookie_samesite') ?: '(unset)'));

        return $tiles;
    }

    // ────────── Database ──────────
    private static function databaseTiles(): array
    {
        $tiles = [];
        $tiles[] = self::tile('DB_PATH', self::STATUS_OK, (string) ($_ENV['DB_PATH'] ?? '(unset)'));

        try {
            $db = Database::connection();
            $version = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
            $tiles[] = self::tile('SQLite version', self::STATUS_OK, $version);

            $counts = [
                'donations'    => (int) $db->query('SELECT COUNT(*) FROM donations')->fetchColumn(),
                'stripe_events'=> (int) $db->query('SELECT COUNT(*) FROM stripe_events')->fetchColumn(),
                'page_views'   => (int) $db->query('SELECT COUNT(*) FROM page_views')->fetchColumn(),
                'admin_audit'  => (int) $db->query('SELECT COUNT(*) FROM admin_audit')->fetchColumn(),
            ];
            foreach ($counts as $table => $n) {
                $tiles[] = self::tile("rows: {$table}", self::STATUS_OK, number_format($n));
            }

            $healthAll      = HealthCheck::all();
            $missingIndexes = $healthAll['missing_indexes'];
            $tiles[] = self::tile(
                'Indexes',
                $missingIndexes === [] ? self::STATUS_OK : self::STATUS_WARN,
                $missingIndexes === [] ? 'all present' : count($missingIndexes) . ' missing',
                $missingIndexes === [] ? null : 'Missing: ' . implode(', ', $missingIndexes),
            );
        } catch (\Throwable $e) {
            $tiles[] = self::tile('SQLite', self::STATUS_BAD, 'connection failed', $e->getMessage());
        }

        return $tiles;
    }

    // ────────── Filesystem ──────────
    private static function filesystemTiles(): array
    {
        $tiles = [];
        $root = dirname(__DIR__, 2);

        $envPath = $root . '/.env';
        $tiles[] = self::fileWritableTile('.env', $envPath);

        $dbPath = (string) ($_ENV['DB_PATH'] ?? '');
        if ($dbPath !== '') {
            $tiles[] = self::fileWritableTile('DB file', $dbPath);
        }

        $logsDir = $root . '/storage/logs';
        $tiles[] = self::dirWritableTile('logs dir', $logsDir);

        return $tiles;
    }

    // ────────── Logs ──────────
    private static function logTiles(): array
    {
        $tiles = [];
        $errorLog = (string) (ini_get('error_log') ?: '');

        if ($errorLog === '') {
            $tiles[] = self::tile('PHP error_log', self::STATUS_WARN, '(SAPI default)', 'Logs go to the web server error log, not a dedicated file.');
            return $tiles;
        }

        $tiles[] = self::tile('PHP error_log', self::STATUS_OK, $errorLog);

        if (!file_exists($errorLog)) {
            $tiles[] = self::tile('Log file exists', self::STATUS_WARN, 'no', 'No entries written yet.');
            return $tiles;
        }

        $size = (int) @filesize($errorLog);
        $mtime = (int) @filemtime($errorLog);
        $age = $mtime > 0 ? time() - $mtime : null;

        $tiles[] = self::tile('Log file size', self::STATUS_OK, self::humanBytes($size));

        if ($age === null) {
            $tiles[] = self::tile('Last log write', self::STATUS_GONE, 'never');
        } else {
            $tiles[] = self::tile(
                'Last log write',
                self::STATUS_OK,
                self::humanAge($age),
                date('Y-m-d H:i', $mtime),
            );
        }
        return $tiles;
    }

    /** @return list<string> Last N lines of the PHP error log, oldest-first. */
    private static function logTail(int $n): array
    {
        $errorLog = (string) (ini_get('error_log') ?: '');
        if ($errorLog === '' || !is_readable($errorLog)) {
            return [];
        }
        // Cheap-and-correct: read the whole file if small (<256KB); otherwise
        // seek to roughly the last 64KB. Diagnostics is admin-only and low
        // traffic so we don't need perfect tail-with-ring-buffer behavior.
        $size = (int) @filesize($errorLog);
        $fh = @fopen($errorLog, 'rb');
        if ($fh === false) {
            return [];
        }
        $chunk = '';
        try {
            if ($size > 65536) {
                fseek($fh, -65536, SEEK_END);
                fgets($fh); // discard partial first line
            }
            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) {
                    break;
                }
                $chunk .= $line;
            }
        } finally {
            fclose($fh);
        }
        $lines = preg_split('/\r?\n/', rtrim($chunk, "\r\n"));
        if ($lines === false) {
            return [];
        }
        $slice = array_slice($lines, -max(1, $n));
        // Trim each line to a readable width; full stack traces can be 500+ chars.
        return array_map(static fn ($l) => mb_substr((string) $l, 0, 240), $slice);
    }

    // ────────── Env presence (non-secret, non-Stripe) ──────────
    private static function envTiles(): array
    {
        $show = [
            'APP_URL', 'APP_TIMEZONE', 'DB_PATH',
            'DONATION_MIN_CENTS', 'DONATION_MAX_CENTS',
            'TRUSTED_PROXIES', 'SESSION_NAME',
            'STRIPE_PUB_KEY', 'STRIPE_TEST_PUB_KEY',
        ];
        $tiles = [];
        foreach ($show as $key) {
            $v = (string) ($_ENV[$key] ?? '');
            if ($v === '') {
                $tiles[] = self::tile($key, self::STATUS_WARN, '(empty)', null);
            } else {
                $tiles[] = self::tile($key, self::STATUS_OK, $v);
            }
        }

        // Admin basic-auth — show username but not password. ADMIN_PASS empty
        // would lock the panel entirely, so the admin can't actually be
        // viewing this page if it's missing. Included for completeness.
        $tiles[] = self::tile(
            'ADMIN_USER',
            empty($_ENV['ADMIN_USER']) ? self::STATUS_BAD : self::STATUS_OK,
            empty($_ENV['ADMIN_USER']) ? '(unset)' : (string) $_ENV['ADMIN_USER'],
        );
        $tiles[] = self::tile(
            'ADMIN_PASS',
            empty($_ENV['ADMIN_PASS']) ? self::STATUS_BAD : self::STATUS_OK,
            empty($_ENV['ADMIN_PASS']) ? '(unset)' : 'set',
        );

        return $tiles;
    }

    /**
     * All tiles for a single Stripe mode (live or test): key presence + format,
     * webhook heartbeat, Stripe account health, balance, endpoint, webhook
     * secret format. Caller renders each mode's return value in its own
     * sectioned panel so live and test never mix visually.
     *
     * @return list<array{label:string,status:string,value:string,detail:?string}>
     */
    private static function stripeModeTiles(string $mode): array
    {
        $up = strtoupper($mode);                                        // LIVE / TEST
        $secretKey     = "STRIPE_{$up}_SECRET_KEY";
        $webhookKey    = "STRIPE_{$up}_WEBHOOK_SECRET";
        $expectedSk    = $mode === AppConfig::MODE_LIVE ? '/^(sk|rk)_live_[A-Za-z0-9]+$/' : '/^(sk|rk)_test_[A-Za-z0-9]+$/';
        $expectedSkLbl = $mode === AppConfig::MODE_LIVE ? 'sk_live_…' : 'sk_test_…';

        $tiles = [];

        // 1. Key presence + format. Labels drop the STRIPE_ prefix since the
        //    section header already says which mode we're in.
        $tiles[] = self::keyFormatTile('Secret key', $secretKey, $expectedSk, $expectedSkLbl);
        $tiles[] = self::keyFormatTile('Webhook secret', $webhookKey, '/^whsec_[A-Za-z0-9]+$/', 'whsec_…');

        // 2. Heartbeat (from the local stripe_events table).
        $tiles[] = self::heartbeatTile($mode === AppConfig::MODE_LIVE);

        // 3. Stripe API health — but only if the mode's keys resolve. Legacy
        //    STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET are honored as a live-
        //    mode fallback so older .env files keep working.
        $creds = AppConfig::resolveStripeCredentials($mode, $_ENV);
        if ($creds === null) {
            $tiles[] = self::tile(
                'Stripe API',
                self::STATUS_GONE,
                'keys not configured',
                "Set {$secretKey} and {$webhookKey} in .env to enable API checks.",
            );
            return $tiles;
        }

        return array_merge($tiles, self::stripeApiTilesForMode($creds['secret']));
    }

    /**
     * @return list<array{label:string,status:string,value:string,detail:?string}>
     */
    private static function stripeApiTilesForMode(string $secret): array
    {
        $client = new StripeClient(['api_key' => $secret, 'stripe_version' => '2026-03-25.dahlia']);
        $tiles = [];

        try {
            $account = $client->accounts->retrieve();
            $chargesOk = (bool) ($account->charges_enabled ?? false);
            $payoutsOk = (bool) ($account->payouts_enabled ?? false);

            $name = (string) ($account->business_profile->name ?? $account->email ?? $account->id);
            $tiles[] = self::tile(
                'Stripe account',
                self::STATUS_OK,
                $name,
                'Country: ' . (string) ($account->country ?? '?')
                    . ' • Currency: ' . strtoupper((string) ($account->default_currency ?? '?')),
            );
            $tiles[] = self::tile(
                'charges_enabled',
                $chargesOk ? self::STATUS_OK : self::STATUS_BAD,
                $chargesOk ? 'yes' : 'no',
                $chargesOk ? null : 'Account cannot accept payments.',
            );
            $tiles[] = self::tile(
                'payouts_enabled',
                $payoutsOk ? self::STATUS_OK : self::STATUS_WARN,
                $payoutsOk ? 'yes' : 'no',
                $payoutsOk ? null : 'Funds will accumulate but not pay out.',
            );
        } catch (\Throwable $e) {
            $tiles[] = self::tile('Stripe account', self::STATUS_BAD, 'API call failed', $e->getMessage());
            return $tiles; // key is bad; no point continuing
        }

        try {
            $balance = $client->balance->retrieve();
            $available = self::sumBalance($balance->available ?? []);
            $pending   = self::sumBalance($balance->pending ?? []);
            $tiles[] = self::tile(
                'Balance',
                self::STATUS_OK,
                'available ' . self::fmtDollars($available) . ' • pending ' . self::fmtDollars($pending),
            );
        } catch (\Throwable $e) {
            $tiles[] = self::tile('Balance', self::STATUS_WARN, 'unavailable', $e->getMessage());
        }

        // Webhook endpoint — must match our app URL, be enabled, and subscribe
        // to every event type our handler dispatches.
        try {
            $endpoints = $client->webhookEndpoints->all(['limit' => 30]);
            $expectedUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/webhook.php';
            $match = null;
            foreach ($endpoints->data as $ep) {
                if (rtrim((string) $ep->url, '/') === rtrim($expectedUrl, '/')) {
                    $match = $ep;
                    break;
                }
            }
            if ($match === null) {
                $tiles[] = self::tile(
                    'Webhook endpoint',
                    self::STATUS_BAD,
                    'not found',
                    'No Stripe webhook endpoint points at ' . $expectedUrl,
                );
            } else {
                $status  = (string) ($match->status ?? '?');
                $enabled = $status === 'enabled';
                $events  = (array) ($match->enabled_events ?? []);
                $hasAll  = in_array('*', $events, true);
                $missing = $hasAll ? [] : array_values(array_diff(self::REQUIRED_EVENT_TYPES, $events));

                $tileStatus = self::STATUS_OK;
                if (!$enabled)          { $tileStatus = self::STATUS_BAD; }
                elseif ($missing !== []) { $tileStatus = self::STATUS_WARN; }

                $value = $enabled
                    ? ($missing === [] ? 'subscribed, all events' : 'subscribed, ' . count($missing) . ' events missing')
                    : "disabled ({$status})";

                $detail = 'URL: ' . (string) $match->url;
                if ($missing !== []) {
                    $detail .= ' • Missing: ' . implode(', ', $missing);
                }
                $tiles[] = self::tile('Webhook endpoint', $tileStatus, $value, $detail);
            }
        } catch (\Throwable $e) {
            $tiles[] = self::tile('Webhook endpoint', self::STATUS_WARN, 'lookup failed', $e->getMessage());
        }

        return $tiles;
    }

    /**
     * Legacy unprefixed STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET — rendered
     * as a separate small section so they don't visually compete with the
     * live/test panels above. Only shown when at least one is set, since a
     * fresh install with only mode-prefixed keys wouldn't have them.
     *
     * @return list<array{label:string,status:string,value:string,detail:?string}>
     */
    private static function legacyStripeKeyTiles(): array
    {
        $skSet    = !empty($_ENV['STRIPE_SECRET_KEY']);
        $whsecSet = !empty($_ENV['STRIPE_WEBHOOK_SECRET']);
        if (!$skSet && !$whsecSet) {
            return [];
        }
        return [
            self::keyFormatTile('STRIPE_SECRET_KEY',     'STRIPE_SECRET_KEY',     '/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', 'sk_… (legacy live fallback)'),
            self::keyFormatTile('STRIPE_WEBHOOK_SECRET', 'STRIPE_WEBHOOK_SECRET', '/^whsec_[A-Za-z0-9]+$/',               'whsec_… (legacy live fallback)'),
        ];
    }

    /** Build a "key present + format valid" tile without revealing the value. */
    private static function keyFormatTile(string $label, string $envKey, string $regex, string $expectedLabel): array
    {
        $v = (string) ($_ENV[$envKey] ?? '');
        if ($v === '') {
            return self::tile($label, self::STATUS_GONE, 'not set');
        }
        $ok = (bool) preg_match($regex, $v);
        return self::tile(
            $label,
            $ok ? self::STATUS_OK : self::STATUS_BAD,
            $ok ? 'OK' : 'BAD format',
            $ok ? "expected {$expectedLabel}" : "expected {$expectedLabel} — value does not match",
        );
    }

    /** Webhook-heartbeat tile for one mode, reading stripe_events.livemode. */
    private static function heartbeatTile(bool $live): array
    {
        try {
            $metrics = new Metrics(Database::connection(), isLive: true);
            $ts = $metrics->lastWebhookAt($live);
        } catch (\Throwable $e) {
            return self::tile('Last webhook', self::STATUS_BAD, 'query failed', $e->getMessage());
        }
        if ($ts === null) {
            return self::tile('Last webhook', self::STATUS_GONE, 'never');
        }
        $age = time() - $ts;
        $status = $age < 3600 ? self::STATUS_OK
                : ($age < 86400 ? self::STATUS_WARN : self::STATUS_BAD);
        return self::tile('Last webhook', $status, self::humanAge($age), date('Y-m-d H:i', $ts));
    }

    // ────────── Helpers ──────────
    /**
     * @return array{label:string,status:string,value:string,detail:?string}
     */
    private static function tile(string $label, string $status, string $value, ?string $detail = null): array
    {
        return ['label' => $label, 'status' => $status, 'value' => $value, 'detail' => $detail];
    }

    private static function fileWritableTile(string $label, string $path): array
    {
        if (!file_exists($path)) {
            return self::tile($label, self::STATUS_BAD, 'missing', $path);
        }
        $writable = is_writable($path);
        return self::tile(
            $label,
            $writable ? self::STATUS_OK : self::STATUS_WARN,
            $writable ? 'writable' : 'read-only',
            $path . ' (' . self::humanBytes((int) @filesize($path)) . ')',
        );
    }

    private static function dirWritableTile(string $label, string $path): array
    {
        if (!is_dir($path)) {
            return self::tile($label, self::STATUS_WARN, 'missing', $path);
        }
        $writable = is_writable($path);
        $count = 0;
        foreach (glob($path . '/*') ?: [] as $_) { $count++; }
        return self::tile(
            $label,
            $writable ? self::STATUS_OK : self::STATUS_BAD,
            $writable ? "writable ({$count} files)" : 'read-only',
            $path,
        );
    }

    /** @param iterable<object> $entries Stripe balance entries, each with amount + currency. */
    private static function sumBalance(iterable $entries): int
    {
        $total = 0;
        foreach ($entries as $e) {
            // Only USD; diagnostics doesn't need to FX-convert.
            if (strtolower((string) ($e->currency ?? '')) === 'usd') {
                $total += (int) ($e->amount ?? 0);
            }
        }
        return $total;
    }

    private static function fmtDollars(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    private static function humanBytes(int $n): string
    {
        if ($n < 1024)           { return $n . ' B'; }
        if ($n < 1024 * 1024)    { return number_format($n / 1024, 1) . ' KB'; }
        if ($n < 1024 ** 3)      { return number_format($n / (1024 * 1024), 1) . ' MB'; }
        return number_format($n / (1024 ** 3), 2) . ' GB';
    }

    private static function humanAge(int $secs): string
    {
        if ($secs < 60)    { return $secs . 's ago'; }
        if ($secs < 3600)  { return intdiv($secs, 60) . 'm ago'; }
        if ($secs < 86400) { return intdiv($secs, 3600) . 'h ago'; }
        $d = intdiv($secs, 86400);
        $h = intdiv($secs % 86400, 3600);
        return $d . 'd ' . $h . 'h ago';
    }
}
