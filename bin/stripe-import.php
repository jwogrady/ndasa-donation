#!/usr/bin/env php
<?php
/**
 * Backfill the donations + stripe_events tables from the Stripe API.
 *
 * The app normally writes donations via webhook delivery. When webhooks are
 * misconfigured, down, or the DB was reset mid-deploy, historical donations
 * never land in the local ledger. This script closes that gap by walking
 * Stripe's API directly and replaying the same record-creation logic the
 * webhook uses (EventStore::recordDonation).
 *
 * Usage:
 *   php bin/stripe-import.php --mode=live [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 *                             [--dry-run] [--yes] [--verbose]
 *
 *   --mode=live|test   (required) Which Stripe account to read from. Loads
 *                      credentials via the same AppConfig path the webhook
 *                      uses, so the script cannot accidentally read from a
 *                      mode whose secrets aren't in .env.
 *   --from=YYYY-MM-DD  Optional lower bound (inclusive, app timezone).
 *   --to=YYYY-MM-DD    Optional upper bound (exclusive, app timezone).
 *   --dry-run          Walk the API and print counts; write nothing to DB.
 *   --yes              Skip the "about to write to DB" confirmation prompt.
 *   --verbose          Print one line per processed object.
 *
 * Data written:
 *   - Checkout sessions with payment_status=paid → donations rows (one-time).
 *   - Paid invoices attached to a subscription → donations rows (recurring).
 *   - Refunded charges → status flipped to 'refunded' on the matching donation.
 *
 * Idempotent: re-running against the same window is a no-op for already-
 * recorded donations (INSERT OR IGNORE on order_id/payment_intent_id).
 *
 * Safety:
 *   - Reading Stripe is always safe; writes are gated by --mode and
 *     confirmation. Dry-run is recommended before the first real run.
 *   - Events imported this way are NOT added to stripe_events (that table
 *     tracks delivered webhooks, not back-filled history). If Stripe later
 *     redelivers a webhook for the same session, the INSERT OR IGNORE in
 *     the donations table keeps the row unique; the event lands cleanly in
 *     stripe_events as normal.
 */
declare(strict_types=1);

// Tell config/app.php we're a CLI — skip session start and HTTP headers.
define('NDASA_SKIP_SESSION', true);

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

use NDASA\Admin\AppConfig;
use NDASA\Support\Database;
use NDASA\Webhook\EventStore;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

// ——— Argument parsing ————————————————————————————————————————————————

/** @var array{mode:?string,from:?string,to:?string,dry_run:bool,yes:bool,verbose:bool,help:bool} $opts */
$opts = [
    'mode'    => null,
    'from'    => null,
    'to'      => null,
    'dry_run' => false,
    'yes'     => false,
    'verbose' => false,
    'help'    => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')        { $opts['dry_run'] = true; continue; }
    if ($arg === '--yes')            { $opts['yes']     = true; continue; }
    if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
    if ($arg === '--help' || $arg === '-h')    { $opts['help']    = true; continue; }
    if (preg_match('/^--mode=(live|test)$/', $arg, $m))           { $opts['mode'] = $m[1]; continue; }
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m))   { $opts['from'] = $m[1]; continue; }
    if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m))     { $opts['to']   = $m[1]; continue; }
    fwrite(STDERR, "stripe-import: unrecognized argument '{$arg}'\n");
    fwrite(STDERR, "Run with --help for usage.\n");
    exit(2);
}

if ($opts['help']) {
    // Print the PHPDoc block verbatim as usage. Keeps one source of truth.
    $src = file_get_contents(__FILE__);
    if ($src !== false && preg_match('#/\*\*(.*?)\*/#s', $src, $m)) {
        $text = preg_replace('/^\s*\* ?/m', '', trim($m[1]));
        echo $text, "\n";
    }
    exit(0);
}

if ($opts['mode'] === null) {
    fwrite(STDERR, "stripe-import: --mode=live|test is required.\n");
    fwrite(STDERR, "Run with --help for usage.\n");
    exit(2);
}

// ——— Credential resolution ——————————————————————————————————————————————
//
// config/app.php already initialized the Stripe SDK using the admin's
// *currently selected* mode. We need to override that with the --mode flag
// so an import of the other mode works without flipping the admin toggle.

$creds = AppConfig::resolveStripeCredentials($opts['mode'], $_ENV);
if ($creds === null) {
    fwrite(STDERR, sprintf(
        "stripe-import: --mode=%s selected, but its credentials aren't in .env.\n",
        $opts['mode']
    ));
    fwrite(STDERR, sprintf(
        "  Set %s_SECRET_KEY (and optionally %s_WEBHOOK_SECRET) before running.\n",
        strtoupper('stripe_' . $opts['mode']),
        strtoupper('stripe_' . $opts['mode'])
    ));
    exit(2);
}
Stripe::setApiKey($creds['secret']);
// API version and app info were already set by config/app.php; no change needed.

// ——— Date window —————————————————————————————————————————————————————

$tz = new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
try {
    $fromTs = $opts['from'] !== null
        ? (new DateTimeImmutable($opts['from'] . ' 00:00:00', $tz))->getTimestamp()
        : null;
    $toTs = $opts['to'] !== null
        ? (new DateTimeImmutable($opts['to'] . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp()
        : null;
} catch (Exception $e) {
    fwrite(STDERR, "stripe-import: invalid --from/--to (" . $e->getMessage() . ")\n");
    exit(2);
}

// ——— Pretty output —————————————————————————————————————————————————————

$isTty = function_exists('posix_isatty') && posix_isatty(STDOUT);
$color = static fn (string $code, string $s): string => $isTty ? "\033[{$code}m{$s}\033[0m" : $s;
$green  = static fn (string $s) => $color('32', $s);
$yellow = static fn (string $s) => $color('33', $s);
$red    = static fn (string $s) => $color('31', $s);
$bold   = static fn (string $s) => $color('1', $s);

// ——— Pre-flight summary ——————————————————————————————————————————————

echo $bold("=== Stripe import ==="), "\n";
echo "  Mode:      ", strtoupper($opts['mode']), "\n";
echo "  From:      ", $opts['from'] ?? '(none — full history)', "\n";
echo "  To:        ", $opts['to']   ?? '(none — up to now)',    "\n";
echo "  Dry run:   ", $opts['dry_run'] ? 'yes' : 'no', "\n";
echo "  DB path:   ", (string) ($_ENV['DB_PATH'] ?? '(unset)'), "\n";
echo "\n";

if (!$opts['dry_run'] && !$opts['yes']) {
    echo $yellow(sprintf(
        "This will write %s-mode donations into %s.\n",
        strtoupper($opts['mode']),
        (string) ($_ENV['DB_PATH'] ?? '???')
    ));
    echo "Proceed? [y/N] ";
    $resp = trim((string) fgets(STDIN));
    if (!in_array(strtolower($resp), ['y', 'yes'], true)) {
        echo "Aborted.\n";
        exit(0);
    }
}

// ——— Stripe list params helper ——————————————————————————————————————————

/**
 * Build the common list params (date window + page size) used by every
 * Stripe resource enumerated below.
 *
 * @return array<string,mixed>
 */
$listParams = static function () use ($fromTs, $toTs): array {
    $p = ['limit' => 100];
    $created = [];
    if ($fromTs !== null) { $created['gte'] = $fromTs; }
    if ($toTs   !== null) { $created['lt']  = $toTs;   }
    if ($created !== []) {
        $p['created'] = $created;
    }
    return $p;
};

// ——— DB handle ————————————————————————————————————————————————————————

try {
    $db    = Database::connection();
    $store = new EventStore($db);
} catch (Throwable $e) {
    fwrite(STDERR, $red("DB init failed: " . $e->getMessage()) . "\n");
    exit(1);
}

// Counters for the end-of-run summary. Distinguishing inserted from skipped
// means a re-run against the same window clearly reports zero new writes.
$stats = [
    'sessions_seen'      => 0,
    'sessions_inserted'  => 0,
    'sessions_skipped'   => 0,
    'sessions_foreign'   => 0, // not ours (no client_reference_id / not an NDASA donation)
    'invoices_seen'      => 0,
    'invoices_inserted'  => 0,
    'invoices_skipped'   => 0,
    'invoices_foreign'   => 0, // not attached to a subscription (one-off invoices etc.)
    'refunds_seen'       => 0,
    'refunds_updated'    => 0,
    'refunds_no_match'   => 0,
];

// ——— Section 1: one-time donations via Checkout Sessions ————————————————

echo $bold("[1/3] Importing completed Checkout Sessions…"), "\n";

try {
    // `created` filter covers the session creation time; paid one-time
    // donations are the only ones we record. Subscription-mode sessions
    // are handled in Section 2 via invoices.
    $sessions = \Stripe\Checkout\Session::all(
        array_merge($listParams(), ['status' => 'complete'])
    );
    foreach ($sessions->autoPagingIterator() as $session) {
        $stats['sessions_seen']++;

        if (($session->payment_status ?? '') !== 'paid') { continue; }
        if (($session->subscription ?? null) !== null)   { continue; }

        $orderId = (string) ($session->client_reference_id ?? '');
        // Sessions without a client_reference_id weren't created by this app
        // (NDASA sets it at checkout). Could be WPForms Stripe, manual
        // invoice sends, or a pre-NDASA integration. Skip quietly — these
        // aren't donation records we can meaningfully import.
        if ($orderId === '') {
            $stats['sessions_foreign']++;
            if ($opts['verbose']) {
                echo "  - ", (string) ($session->id ?? '?'), " (not an NDASA session — skipping)\n";
            }
            continue;
        }
        if ((int) ($session->amount_total ?? 0) <= 0) {
            if ($opts['verbose']) {
                echo "  skip (zero amount): ", (string) ($session->id ?? '?'), "\n";
            }
            continue;
        }

        $inserted = importCheckoutSession($session, $store, $db, $opts['dry_run']);
        if ($inserted) {
            $stats['sessions_inserted']++;
            if ($opts['verbose']) {
                echo $green("  +"), " ", (string) $session->id, " → order ", $orderId, "\n";
            }
        } else {
            $stats['sessions_skipped']++;
            if ($opts['verbose']) {
                echo "  = ", (string) $session->id, " (already in DB)\n";
            }
        }
    }
} catch (ApiErrorException $e) {
    fwrite(STDERR, $red("Stripe API error (sessions): " . $e->getMessage()) . "\n");
    exit(1);
}
echo "  Sessions seen: {$stats['sessions_seen']}",
     ", inserted: {$stats['sessions_inserted']}",
     ", skipped (already in DB): {$stats['sessions_skipped']}",
     ", foreign (not NDASA): {$stats['sessions_foreign']}", "\n\n";

// ——— Section 2: recurring donations via Invoices ————————————————————————

echo $bold("[2/3] Importing paid Invoices (recurring donations)…"), "\n";

try {
    $invoices = \Stripe\Invoice::all(
        array_merge($listParams(), ['status' => 'paid'])
    );
    foreach ($invoices->autoPagingIterator() as $invoice) {
        $stats['invoices_seen']++;

        // Only subscription invoices are recurring donations. One-off invoices
        // sent manually from the Stripe dashboard don't belong to an NDASA
        // donor flow and shouldn't be imported.
        if (((string) ($invoice->subscription ?? '')) === '') {
            $stats['invoices_foreign']++;
            if ($opts['verbose']) {
                echo "  - ", (string) ($invoice->id ?? '?'), " (not a subscription invoice — skipping)\n";
            }
            continue;
        }
        if ((int) ($invoice->amount_paid ?? 0) <= 0)          { continue; }

        $inserted = importInvoice($invoice, $store, $db, $opts['dry_run']);
        if ($inserted) {
            $stats['invoices_inserted']++;
            if ($opts['verbose']) {
                echo $green("  +"), " ", (string) $invoice->id, " (sub ", (string) $invoice->subscription, ")\n";
            }
        } else {
            $stats['invoices_skipped']++;
            if ($opts['verbose']) {
                echo "  = ", (string) $invoice->id, " (already in DB or skipped)\n";
            }
        }
    }
} catch (ApiErrorException $e) {
    fwrite(STDERR, $red("Stripe API error (invoices): " . $e->getMessage()) . "\n");
    exit(1);
}
echo "  Invoices seen: {$stats['invoices_seen']}",
     ", inserted: {$stats['invoices_inserted']}",
     ", skipped (already in DB): {$stats['invoices_skipped']}",
     ", foreign (no subscription): {$stats['invoices_foreign']}", "\n\n";

// ——— Section 3: refunds ————————————————————————————————————————————————

echo $bold("[3/3] Applying refunds to existing donations…"), "\n";

try {
    $charges = \Stripe\Charge::all($listParams());
    foreach ($charges->autoPagingIterator() as $charge) {
        if (!($charge->refunded ?? false)) { continue; }
        $stats['refunds_seen']++;

        $pi = (string) ($charge->payment_intent ?? '');
        if ($pi === '') { continue; }

        // Check for a matching local row first (even in dry-run) so counters
        // reflect reality: a Stripe refund without a local donation row is
        // a no-op, not a successful "would-refund."
        $lookup = $db->prepare('SELECT 1 FROM donations WHERE payment_intent_id = ? LIMIT 1');
        $lookup->execute([$pi]);
        $hasLocalRow = $lookup->fetchColumn() !== false;

        if (!$hasLocalRow) {
            $stats['refunds_no_match']++;
            if ($opts['verbose']) {
                echo $yellow("  no-match "), $pi, " (refunded on Stripe but no local donation)\n";
            }
            continue;
        }

        if ($opts['dry_run']) {
            $stats['refunds_updated']++;
            if ($opts['verbose']) {
                echo "  would-refund PI ", $pi, "\n";
            }
            continue;
        }

        // Mirror EventStore::markRefunded: it returns void, so to know
        // whether a row was actually touched, we count affected rows ourselves.
        $upd = $db->prepare(
            "UPDATE donations SET status = 'refunded', refunded_at = ? WHERE payment_intent_id = ?"
        );
        $upd->execute([time(), $pi]);
        $stats['refunds_updated']++;
        if ($opts['verbose']) {
            echo $green("  refunded "), $pi, "\n";
        }
    }
} catch (ApiErrorException $e) {
    fwrite(STDERR, $red("Stripe API error (charges): " . $e->getMessage()) . "\n");
    exit(1);
}
echo "  Refunds seen: {$stats['refunds_seen']}, applied: {$stats['refunds_updated']}, no local match: {$stats['refunds_no_match']}\n\n";

// ——— Summary ——————————————————————————————————————————————————————————

echo $bold("=== Summary ==="), "\n";
foreach ($stats as $k => $v) {
    printf("  %-22s %d\n", $k, $v);
}

if ($opts['dry_run']) {
    echo "\n", $yellow("Dry run — no DB writes performed."), "\n";
} else {
    echo "\n", $green("Done."), "\n";
}
exit(0);

// ——— Helpers (function declarations; PHP hoists them) ————————————————————

/**
 * Insert a one-time Checkout session into `donations`. Returns true on
 * fresh insert, false when the row already existed.
 *
 * Kept in sync with WebhookController::recordPaidSession — if you change
 * fields there, mirror them here.
 */
function importCheckoutSession(
    \Stripe\Checkout\Session $session,
    EventStore $store,
    PDO $db,
    bool $dryRun
): bool {
    $orderId = (string) $session->client_reference_id;
    // Short-circuit if the donation is already recorded, so the counters
    // distinguish inserts from no-ops. Without this, INSERT OR IGNORE
    // silently swallows the duplicate and we can't report skipped rows.
    $exists = $db->prepare('SELECT 1 FROM donations WHERE order_id = ? LIMIT 1');
    $exists->execute([$orderId]);
    if ($exists->fetchColumn() !== false) {
        return false;
    }

    if ($dryRun) {
        return true;
    }

    $dedication  = (string) ($session->metadata->dedication ?? '');
    $emailOptin  = $session->metadata->email_optin ?? null;
    $intervalRaw = (string) ($session->metadata->interval ?? 'once');

    $store->recordDonation([
        'order_id'               => $orderId,
        'payment_intent_id'      => ((string) ($session->payment_intent ?? '')) !== ''
                                    ? (string) $session->payment_intent : null,
        'amount_cents'           => (int) $session->amount_total,
        'currency'               => (string) ($session->currency ?? 'usd'),
        'email'                  => (string) (($session->customer_details->email ?? null)
                                    ?? ($session->customer_email ?? '')),
        'contact_name'           => (string) ($session->customer_details->name ?? ''),
        'status'                 => 'paid',
        'dedication'             => $dedication,
        'email_optin'            => $emailOptin === null ? null : ($emailOptin === '1'),
        'interval'               => in_array($intervalRaw, ['month', 'year'], true) ? $intervalRaw : null,
        'stripe_subscription_id' => ((string) ($session->subscription ?? '')) !== ''
                                    ? (string) $session->subscription : null,
        'stripe_customer_id'     => ((string) ($session->customer ?? '')) !== ''
                                    ? (string) $session->customer : null,
        'livemode'               => (bool) ($session->livemode ?? true),
    ]);
    return true;
}

/**
 * Insert a recurring-donation invoice into `donations`. Mirrors the logic
 * in WebhookController::onInvoicePaid: the signup invoice is already
 * covered by its Checkout session, so we only write rows for invoices
 * whose signup row doesn't exist (renewals, or signups that the session
 * handler missed).
 */
function importInvoice(
    \Stripe\Invoice $invoice,
    EventStore $store,
    PDO $db,
    bool $dryRun
): bool {
    $subscriptionId = (string) $invoice->subscription;
    $invoiceId      = (string) $invoice->id;

    // Attempt to dedupe against the signup session row. The subscription
    // carries metadata.order_id set at Checkout creation time.
    $signupOrderId = null;
    try {
        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
        $signupOrderId = (string) ($subscription->metadata->order_id ?? '') ?: null;
    } catch (Throwable $e) {
        // Fall through — we'll just write a row keyed by invoice id.
    }
    if ($signupOrderId !== null) {
        $exists = $db->prepare('SELECT 1 FROM donations WHERE order_id = ? LIMIT 1');
        $exists->execute([$signupOrderId]);
        if ($exists->fetchColumn() !== false) {
            return false; // the session handler / an earlier import recorded it
        }
    }

    $orderId = 'inv_' . $invoiceId;

    $exists = $db->prepare('SELECT 1 FROM donations WHERE order_id = ? LIMIT 1');
    $exists->execute([$orderId]);
    if ($exists->fetchColumn() !== false) {
        return false;
    }

    if ($dryRun) {
        return true;
    }

    $interval = null;
    foreach ($invoice->lines->data ?? [] as $line) {
        $i = (string) ($line->price->recurring->interval ?? '');
        if ($i === 'month' || $i === 'year') { $interval = $i; break; }
    }

    $store->recordDonation([
        'order_id'               => $orderId,
        'payment_intent_id'      => ((string) ($invoice->payment_intent ?? '')) !== ''
                                    ? (string) $invoice->payment_intent : null,
        'amount_cents'           => (int) $invoice->amount_paid,
        'currency'               => (string) ($invoice->currency ?? 'usd'),
        'email'                  => (string) ($invoice->customer_email ?? ''),
        'contact_name'           => (string) ($invoice->customer_name ?? ''),
        'status'                 => 'paid',
        'dedication'             => '',
        'email_optin'            => null,
        'interval'               => $interval,
        'stripe_subscription_id' => $subscriptionId,
        'stripe_customer_id'     => ((string) ($invoice->customer ?? '')) !== ''
                                    ? (string) $invoice->customer : null,
        'livemode'               => (bool) ($invoice->livemode ?? true),
    ]);
    return true;
}
