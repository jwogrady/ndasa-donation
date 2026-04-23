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

use PDO;

/**
 * Read-only metric queries for the admin dashboard.
 *
 * All counts and sums come from the local SQLite database populated by the
 * Stripe webhook; the Stripe API is not called here. Each query is O(rows)
 * and bounded by a small LIMIT where appropriate.
 */
final class Metrics
{
    /**
     * Per-request memoization. Each metric runs at most one SQL query per
     * instance. Keys are distinct integers / floats, so null can represent
     * "not yet computed" without colliding with a legitimate zero result.
     */
    private ?int   $pageViewCount     = null;
    /** @var ?array{count:int,donors:int,total:int} */
    private ?array $donationAggregate = null;
    private ?float $conversionPercent = null;

    /**
     * 1 (live) | 0 (test) — filters every donation query so the dashboard
     * only surfaces rows matching the admin's currently active Stripe mode.
     * Page views are not filtered; they're a traffic metric independent of
     * which Stripe mode is handling payments at the moment.
     */
    private readonly int $livemode;

    public function __construct(private readonly PDO $db, bool $isLive = true)
    {
        $this->livemode = $isLive ? 1 : 0;
    }

    public function pageViewCount(): int
    {
        return $this->pageViewCount ??=
            (int) $this->db->query('SELECT COUNT(*) FROM page_views')->fetchColumn();
    }

    /**
     * Single query that returns count, distinct-donor count, and total cents
     * for paid donations. Dashboards call three of these back-to-back; one
     * query with three aggregates is one full-table scan instead of three.
     *
     * @return array{count:int,donors:int,total:int}
     */
    private function donationAggregate(): array
    {
        if ($this->donationAggregate !== null) {
            return $this->donationAggregate;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c, COUNT(DISTINCT lower(email)) AS d, COALESCE(SUM(amount_cents), 0) AS t
             FROM donations WHERE status = 'paid' AND livemode = :lm"
        );
        $stmt->bindValue(':lm', $this->livemode, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'd' => 0, 't' => 0];

        return $this->donationAggregate = [
            'count'  => (int) $row['c'],
            'donors' => (int) $row['d'],
            'total'  => (int) $row['t'],
        ];
    }

    /**
     * Count of donations that represent real money received.
     * Excludes pending, failed, and refunded rows — the dashboard is a
     * revenue view, not an attempt-funnel view.
     */
    public function donationCount(): int
    {
        return $this->donationAggregate()['count'];
    }

    /** Distinct donor count among successful donations (by email, case-insensitive). */
    public function donorCount(): int
    {
        return $this->donationAggregate()['donors'];
    }

    /** Sum of donation amounts in cents, restricted to paid (non-refunded) rows. */
    public function totalDonationCents(): int
    {
        return $this->donationAggregate()['total'];
    }

    /**
     * Conversion rate as a percentage, rounded to 1 decimal place.
     * Returns 0.0 when page_views is 0 (undefined otherwise).
     */
    public function conversionRatePercent(): float
    {
        if ($this->conversionPercent !== null) {
            return $this->conversionPercent;
        }
        $views = $this->pageViewCount();
        if ($views === 0) {
            return $this->conversionPercent = 0.0;
        }
        return $this->conversionPercent = round(($this->donationCount() / $views) * 100, 1);
    }

    /**
     * Most recent donations, newest first. Capped at $limit rows.
     *
     * @return list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,interval:?string}>
     */
    public function recentDonations(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, contact_name, email, amount_cents, currency, status, created_at, refunded_at, dedication, "interval"
             FROM donations
             WHERE livemode = :lm
             ORDER BY created_at DESC
             LIMIT :n'
        );
        $stmt->bindValue(':lm', $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':n', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'order_id'     => (string) $r['order_id'],
                'contact_name' => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
                'email'        => (string) $r['email'],
                'amount_cents' => (int)    $r['amount_cents'],
                'currency'     => (string) $r['currency'],
                'status'       => (string) $r['status'],
                'created_at'   => (int)    $r['created_at'],
                'refunded_at'  => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
                'dedication'   => $r['dedication'] !== null ? (string) $r['dedication'] : null,
                'interval'     => $r['interval'] !== null ? (string) $r['interval'] : null,
            ];
        }
        return $out;
    }

    /**
     * Fetch a single donation by order_id for the admin detail view.
     *
     * @return ?array{order_id:string,payment_intent_id:?string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,email_optin:?bool,interval:?string,stripe_subscription_id:?string,stripe_customer_id:?string}
     */
    public function findDonation(string $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency, status,
                    created_at, refunded_at, dedication, email_optin, "interval",
                    stripe_subscription_id, stripe_customer_id
             FROM donations WHERE order_id = :oid AND livemode = :lm'
        );
        $stmt->execute([':oid' => $orderId, ':lm' => $this->livemode]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }
        return [
            'order_id'               => (string) $r['order_id'],
            'payment_intent_id'      => $r['payment_intent_id'] !== null ? (string) $r['payment_intent_id'] : null,
            'contact_name'           => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
            'email'                  => (string) $r['email'],
            'amount_cents'           => (int)    $r['amount_cents'],
            'currency'               => (string) $r['currency'],
            'status'                 => (string) $r['status'],
            'created_at'             => (int)    $r['created_at'],
            'refunded_at'            => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
            'dedication'             => $r['dedication'] !== null ? (string) $r['dedication'] : null,
            'email_optin'            => $r['email_optin'] !== null ? ((int) $r['email_optin'] === 1) : null,
            'interval'               => $r['interval'] !== null ? (string) $r['interval'] : null,
            'stripe_subscription_id' => $r['stripe_subscription_id'] !== null ? (string) $r['stripe_subscription_id'] : null,
            'stripe_customer_id'     => $r['stripe_customer_id'] !== null ? (string) $r['stripe_customer_id'] : null,
        ];
    }

    /**
     * All paid donations in a [from, to) unix-second range, oldest-first so
     * the CSV export streams in chronological order for bookkeeping.
     *
     * @return list<array{order_id:string,payment_intent_id:?string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,email_optin:?bool,interval:?string,stripe_subscription_id:?string}>
     */
    public function donationsInRange(int $fromTs, int $toTs): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency, status,
                    created_at, refunded_at, dedication, email_optin, "interval", stripe_subscription_id
             FROM donations
             WHERE livemode = :lm AND created_at >= :from AND created_at < :to
             ORDER BY created_at ASC'
        );
        $stmt->bindValue(':lm',   $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':from', $fromTs, PDO::PARAM_INT);
        $stmt->bindValue(':to',   $toTs,   PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'order_id'               => (string) $r['order_id'],
                'payment_intent_id'      => $r['payment_intent_id'] !== null ? (string) $r['payment_intent_id'] : null,
                'contact_name'           => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
                'email'                  => (string) $r['email'],
                'amount_cents'           => (int)    $r['amount_cents'],
                'currency'               => (string) $r['currency'],
                'status'                 => (string) $r['status'],
                'created_at'             => (int)    $r['created_at'],
                'refunded_at'            => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
                'dedication'             => $r['dedication'] !== null ? (string) $r['dedication'] : null,
                'email_optin'            => $r['email_optin'] !== null ? ((int) $r['email_optin'] === 1) : null,
                'interval'               => $r['interval'] !== null ? (string) $r['interval'] : null,
                'stripe_subscription_id' => $r['stripe_subscription_id'] !== null ? (string) $r['stripe_subscription_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Unix timestamp of the most recently received Stripe webhook event, or
     * null if the server has never successfully processed one. Powers the
     * "last webhook" heartbeat on the dashboard — a big visual signal that
     * the ingest pipeline is alive. Not filtered by mode because Stripe
     * delivers both live and test events to the same endpoint; the question
     * is "is the pipe open?", not "are my live funds flowing?".
     */
    public function lastWebhookAt(): ?int
    {
        $v = $this->db->query('SELECT MAX(received_at) FROM stripe_events')->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    /**
     * Active recurring commitment: for every currently-paid subscription,
     * the most recent amount_cents charged, normalized to a monthly number.
     * Yearly subscriptions divide by 12 for apples-to-apples comparison.
     *
     * A subscription counts as "active" if its most recent row is `paid`
     * (not `cancelled` or `refunded`). Multiple invoice rows per subscription
     * are collapsed to the most recent; we use a subquery rather than a
     * window function because SQLite 3.7.17 on prod doesn't support them.
     *
     * @return array{subscriptions:int,monthly_cents:int}
     */
    public function activeRecurringCommitment(): array
    {
        $stmt = $this->db->prepare(
            "SELECT stripe_subscription_id, amount_cents, \"interval\", status
             FROM donations d1
             WHERE livemode = :lm
               AND stripe_subscription_id IS NOT NULL
               AND created_at = (
                   SELECT MAX(created_at) FROM donations d2
                   WHERE d2.stripe_subscription_id = d1.stripe_subscription_id
                     AND d2.livemode = :lm2
               )"
        );
        $stmt->bindValue(':lm',  $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':lm2', $this->livemode, PDO::PARAM_INT);
        $stmt->execute();

        $subs = 0;
        $monthlyCents = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string) $r['status'] !== 'paid') { continue; }
            $subs++;
            $amt = (int) $r['amount_cents'];
            $monthlyCents += ((string) $r['interval']) === 'year'
                ? intdiv($amt, 12)
                : $amt;
        }
        return ['subscriptions' => $subs, 'monthly_cents' => $monthlyCents];
    }

    /**
     * Repeat donors — people who have donated 2+ times. Ordered by total
     * given, capped at $limit rows.
     *
     * Emails are lowercased before grouping so "Jane@x.com" and "jane@x.com"
     * count as the same donor. contact_name uses the most recent donation's
     * name (MAX(created_at) scan), not the first, so donors who changed
     * their display name get the current spelling.
     *
     * @return list<array{email:string,contact_name:?string,donations:int,total_cents:int,last_at:int}>
     */
    public function repeatDonors(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT lower(email) AS email,
                    COUNT(*) AS n,
                    SUM(amount_cents) AS total,
                    MAX(created_at) AS last_at,
                    (SELECT contact_name FROM donations d2
                     WHERE lower(d2.email) = lower(d1.email)
                       AND d2.livemode = :lm2
                       AND d2.status = 'paid'
                     ORDER BY d2.created_at DESC LIMIT 1) AS contact_name
             FROM donations d1
             WHERE status = 'paid' AND livemode = :lm
             GROUP BY lower(email)
             HAVING COUNT(*) > 1
             ORDER BY total DESC
             LIMIT :n"
        );
        $stmt->bindValue(':lm',  $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':lm2', $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':n', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'email'        => (string) $r['email'],
                'contact_name' => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
                'donations'    => (int) $r['n'],
                'total_cents'  => (int) $r['total'],
                'last_at'      => (int) $r['last_at'],
            ];
        }
        return $out;
    }

    /**
     * Daily donation totals and counts for the last N days, oldest-first.
     * Missing days are backfilled with zero rows so the sparkline renders
     * flat instead of gapped. Uses SQLite's date() on a Unix epoch — cheap
     * on the indexed (livemode, created_at) compound index.
     *
     * @return list<array{date:string,count:int,total_cents:int}>
     */
    public function dailyTotalsLast(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $tz = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $end = (new \DateTimeImmutable('today', $tz))->modify('+1 day'); // exclusive
        $start = $end->modify("-{$days} day");

        $stmt = $this->db->prepare(
            "SELECT date(created_at, 'unixepoch', 'localtime') AS d,
                    COUNT(*) AS n,
                    SUM(amount_cents) AS total
             FROM donations
             WHERE livemode = :lm AND status = 'paid'
               AND created_at >= :from AND created_at < :to
             GROUP BY d"
        );
        $stmt->bindValue(':lm',   $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':from', $start->getTimestamp(), PDO::PARAM_INT);
        $stmt->bindValue(':to',   $end->getTimestamp(),   PDO::PARAM_INT);
        $stmt->execute();
        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDate[(string) $r['d']] = [
                'count'       => (int) $r['n'],
                'total_cents' => (int) $r['total'],
            ];
        }

        // Backfill zero days so the sparkline has a constant shape regardless
        // of activity density. Walk in local calendar days; format matches
        // what SQLite's date() returned above so keys align.
        $out = [];
        $cursor = $start;
        while ($cursor < $end) {
            $key = $cursor->format('Y-m-d');
            $out[] = [
                'date'        => $key,
                'count'       => $byDate[$key]['count']       ?? 0,
                'total_cents' => $byDate[$key]['total_cents'] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    /**
     * Refund rate over the last N days as a percentage 0..100. Numerator is
     * rows whose refunded_at falls inside the window; denominator is rows
     * whose created_at falls inside the same window. That gives a "refunds
     * per donation in this window" figure, not a cumulative refund rate,
     * which is the signal operators actually want (is fraud or friction
     * spiking recently?).
     *
     * @return array{donations:int,refunded:int,rate_pct:float}
     */
    public function refundRateLast(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $now = time();
        $from = $now - ($days * 86400);

        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN created_at >= :from THEN 1 ELSE 0 END) AS donations,
                SUM(CASE WHEN refunded_at IS NOT NULL AND refunded_at >= :from2 THEN 1 ELSE 0 END) AS refunded
             FROM donations WHERE livemode = :lm"
        );
        $stmt->bindValue(':lm',   $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':from',  $from, PDO::PARAM_INT);
        $stmt->bindValue(':from2', $from, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['donations' => 0, 'refunded' => 0];

        $donations = (int) ($row['donations'] ?? 0);
        $refunded  = (int) ($row['refunded']  ?? 0);
        $rate = $donations === 0 ? 0.0 : round(($refunded / $donations) * 100, 1);
        return ['donations' => $donations, 'refunded' => $refunded, 'rate_pct' => $rate];
    }

    /**
     * Paginated, filterable transactions list for the transactions index
     * page. All donations (paid, refunded, cancelled), not just the
     * "revenue" subset.
     *
     * @param array{email?:string,status?:string,from_ts?:?int,to_ts?:?int,limit:int,offset:int} $f
     * @return list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,interval:?string,created_at:int,refunded_at:?int,dedication:?string,stripe_subscription_id:?string}>
     */
    public function listTransactions(array $f): array
    {
        [$sql, $params] = $this->buildTxnWhere($f);
        $sql = "SELECT order_id, contact_name, email, amount_cents, currency, status, \"interval\",
                       created_at, refunded_at, dedication, stripe_subscription_id
                FROM donations {$sql}
                ORDER BY created_at DESC
                LIMIT :lim OFFSET :off";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v[0], $v[1]);
        }
        $stmt->bindValue(':lim', max(1, min(500, (int) $f['limit'])),  PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, (int) $f['offset']),           PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'order_id'               => (string) $r['order_id'],
                'contact_name'           => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
                'email'                  => (string) $r['email'],
                'amount_cents'           => (int)    $r['amount_cents'],
                'currency'               => (string) $r['currency'],
                'status'                 => (string) $r['status'],
                'interval'               => $r['interval'] !== null ? (string) $r['interval'] : null,
                'created_at'             => (int)    $r['created_at'],
                'refunded_at'            => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
                'dedication'             => $r['dedication'] !== null ? (string) $r['dedication'] : null,
                'stripe_subscription_id' => $r['stripe_subscription_id'] !== null ? (string) $r['stripe_subscription_id'] : null,
            ];
        }
        return $out;
    }

    /** Row count matching the same filters as listTransactions, for pagination totals. */
    public function countTransactions(array $f): int
    {
        [$sql, $params] = $this->buildTxnWhere($f);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM donations {$sql}");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v[0], $v[1]);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Build the WHERE clause + bound parameters for the transactions list
     * queries. Kept as its own method so listTransactions and
     * countTransactions can share identical filter logic (offset aside).
     *
     * @return array{0:string,1:array<string,array{0:mixed,1:int}>}
     */
    private function buildTxnWhere(array $f): array
    {
        $conds  = ['livemode = :lm'];
        $params = [':lm' => [$this->livemode, PDO::PARAM_INT]];

        $email = isset($f['email']) ? trim((string) $f['email']) : '';
        if ($email !== '') {
            // Case-insensitive substring match — operators search by "jane"
            // or "@example.com", not full addresses.
            $conds[]             = 'lower(email) LIKE :email';
            $params[':email']    = ['%' . strtolower($email) . '%', PDO::PARAM_STR];
        }
        $status = isset($f['status']) ? (string) $f['status'] : '';
        if (in_array($status, ['paid', 'refunded', 'cancelled'], true)) {
            $conds[]             = 'status = :status';
            $params[':status']   = [$status, PDO::PARAM_STR];
        }
        if (!empty($f['from_ts'])) {
            $conds[]             = 'created_at >= :from_ts';
            $params[':from_ts']  = [(int) $f['from_ts'], PDO::PARAM_INT];
        }
        if (!empty($f['to_ts'])) {
            $conds[]             = 'created_at < :to_ts';
            $params[':to_ts']    = [(int) $f['to_ts'], PDO::PARAM_INT];
        }
        return ['WHERE ' . implode(' AND ', $conds), $params];
    }

    /**
     * Subscriptions index — one row per stripe_subscription_id with
     * aggregated totals. Derived status is the status of the most recent
     * invoice row; the subscription detail page hits Stripe for the
     * authoritative state.
     *
     * @return list<array{stripe_subscription_id:string,stripe_customer_id:?string,contact_name:?string,email:string,interval:?string,amount_cents:int,invoices:int,total_cents:int,last_at:int,status:string}>
     */
    public function listSubscriptions(int $limit = 100, int $offset = 0): array
    {
        // Pulls all rows tagged with a subscription id, aggregates to one
        // per sub. The "most recent invoice" is pulled via a correlated
        // subquery so SQLite 3.7.17 compatibility holds (no window funcs).
        $stmt = $this->db->prepare(
            "SELECT stripe_subscription_id AS sub_id,
                    SUM(amount_cents)      AS total,
                    COUNT(*)               AS invoices,
                    MAX(created_at)        AS last_at
             FROM donations
             WHERE livemode = :lm AND stripe_subscription_id IS NOT NULL
             GROUP BY stripe_subscription_id
             ORDER BY last_at DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lm',  $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset),          PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hydrate per-sub fields from the most recent row (status, name, etc.).
        $out = [];
        $latestStmt = $this->db->prepare(
            'SELECT contact_name, email, "interval", amount_cents, status, stripe_customer_id
             FROM donations
             WHERE stripe_subscription_id = :sid AND livemode = :lm
             ORDER BY created_at DESC LIMIT 1'
        );
        foreach ($rows as $r) {
            $latestStmt->execute([':sid' => $r['sub_id'], ':lm' => $this->livemode]);
            $latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out[] = [
                'stripe_subscription_id' => (string) $r['sub_id'],
                'stripe_customer_id'     => ($latest['stripe_customer_id'] ?? null) !== null ? (string) $latest['stripe_customer_id'] : null,
                'contact_name'           => ($latest['contact_name'] ?? null) !== null ? (string) $latest['contact_name'] : null,
                'email'                  => (string) ($latest['email'] ?? ''),
                'interval'               => ($latest['interval'] ?? null) !== null ? (string) $latest['interval'] : null,
                'amount_cents'           => (int)    ($latest['amount_cents'] ?? 0),
                'invoices'               => (int) $r['invoices'],
                'total_cents'            => (int) $r['total'],
                'last_at'                => (int) $r['last_at'],
                'status'                 => (string) ($latest['status'] ?? 'unknown'),
            ];
        }
        return $out;
    }

    public function countSubscriptions(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT stripe_subscription_id) FROM donations
             WHERE livemode = :lm AND stripe_subscription_id IS NOT NULL'
        );
        $stmt->bindValue(':lm', $this->livemode, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * All invoice rows for a single subscription, newest-first. Used by the
     * subscription detail page. Only fetches rows from the active mode so
     * a live-mode admin viewing a sub can't accidentally pull in test-mode
     * rows that share the same sub_id (unlikely but possible).
     *
     * @return list<array{order_id:string,payment_intent_id:?string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int}>
     */
    public function subscriptionInvoices(string $subscriptionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, amount_cents, currency, status, created_at, refunded_at
             FROM donations
             WHERE stripe_subscription_id = :sid AND livemode = :lm
             ORDER BY created_at DESC'
        );
        $stmt->bindValue(':sid', $subscriptionId, PDO::PARAM_STR);
        $stmt->bindValue(':lm',  $this->livemode, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'order_id'          => (string) $r['order_id'],
                'payment_intent_id' => $r['payment_intent_id'] !== null ? (string) $r['payment_intent_id'] : null,
                'amount_cents'      => (int)    $r['amount_cents'],
                'currency'          => (string) $r['currency'],
                'status'            => (string) $r['status'],
                'created_at'        => (int)    $r['created_at'],
                'refunded_at'       => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * Donors index — one row per unique lowercased email, with lifetime
     * totals and first/last gift dates. Most recent contact_name wins.
     *
     * @return list<array{email:string,contact_name:?string,donations:int,total_cents:int,first_at:int,last_at:int}>
     */
    public function listDonors(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT lower(email) AS email,
                    COUNT(*)     AS n,
                    SUM(amount_cents) AS total,
                    MIN(created_at) AS first_at,
                    MAX(created_at) AS last_at,
                    (SELECT contact_name FROM donations d2
                     WHERE lower(d2.email) = lower(d1.email)
                       AND d2.livemode = :lm2 AND d2.status = 'paid'
                     ORDER BY d2.created_at DESC LIMIT 1) AS contact_name
             FROM donations d1
             WHERE status = 'paid' AND livemode = :lm
             GROUP BY lower(email)
             ORDER BY total DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lm',  $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':lm2', $this->livemode, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset),          PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'email'        => (string) $r['email'],
                'contact_name' => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
                'donations'    => (int) $r['n'],
                'total_cents'  => (int) $r['total'],
                'first_at'     => (int) $r['first_at'],
                'last_at'      => (int) $r['last_at'],
            ];
        }
        return $out;
    }

    public function countDonors(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT lower(email)) FROM donations
             WHERE status = 'paid' AND livemode = :lm"
        );
        $stmt->bindValue(':lm', $this->livemode, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Donor detail aggregate — everything a single donor has done. Powers
     * the donor detail page; the donor is keyed by lowercased email which
     * is resolved from a SHA-256 hash in the URL.
     *
     * Returns null if no donor with that hash exists (or no email in the
     * current mode hashes to the given value).
     *
     * @return ?array{email:string,contact_name:?string,total_cents:int,donations:list<array<string,mixed>>,subscriptions:list<string>,last_optin:?bool}
     */
    public function findDonorByEmailHash(string $emailHashHex): ?array
    {
        // Scan distinct emails in the current mode, hash each, find the
        // match. Small N (one row per donor), linear scan is fine.
        $scan = $this->db->prepare(
            "SELECT DISTINCT lower(email) AS email
             FROM donations WHERE livemode = :lm AND status IN ('paid','refunded','cancelled')"
        );
        $scan->bindValue(':lm', $this->livemode, PDO::PARAM_INT);
        $scan->execute();
        $email = null;
        foreach ($scan->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
            if (hash_equals($emailHashHex, hash('sha256', (string) $candidate))) {
                $email = (string) $candidate;
                break;
            }
        }
        if ($email === null) {
            return null;
        }

        // All rows for this donor, newest first.
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency,
                    status, created_at, refunded_at, dedication, email_optin, "interval",
                    stripe_subscription_id, stripe_customer_id
             FROM donations
             WHERE lower(email) = :email AND livemode = :lm
             ORDER BY created_at DESC'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':lm',    $this->livemode, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $donations = [];
        $total = 0;
        $subs = [];
        $contactName = null;
        $lastOptin = null;
        foreach ($rows as $r) {
            if (($r['contact_name'] ?? null) !== null && $contactName === null) {
                $contactName = (string) $r['contact_name']; // newest-first, first non-null wins
            }
            if ($lastOptin === null && ($r['email_optin'] ?? null) !== null) {
                $lastOptin = ((int) $r['email_optin']) === 1;
            }
            if (((string) $r['status']) === 'paid') {
                $total += (int) $r['amount_cents'];
            }
            $sid = (string) ($r['stripe_subscription_id'] ?? '');
            if ($sid !== '' && !in_array($sid, $subs, true)) {
                $subs[] = $sid;
            }
            $donations[] = [
                'order_id'               => (string) $r['order_id'],
                'payment_intent_id'      => $r['payment_intent_id'] !== null ? (string) $r['payment_intent_id'] : null,
                'amount_cents'           => (int)    $r['amount_cents'],
                'currency'               => (string) $r['currency'],
                'status'                 => (string) $r['status'],
                'created_at'             => (int)    $r['created_at'],
                'refunded_at'            => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
                'dedication'             => $r['dedication'] !== null ? (string) $r['dedication'] : null,
                'email_optin'            => $r['email_optin'] !== null ? ((int) $r['email_optin']) === 1 : null,
                'interval'               => $r['interval'] !== null ? (string) $r['interval'] : null,
                'stripe_subscription_id' => $sid !== '' ? $sid : null,
                'stripe_customer_id'     => $r['stripe_customer_id'] !== null ? (string) $r['stripe_customer_id'] : null,
            ];
        }
        return [
            'email'         => $email,
            'contact_name'  => $contactName,
            'total_cents'   => $total,
            'donations'     => $donations,
            'subscriptions' => $subs,
            'last_optin'    => $lastOptin,
        ];
    }

    /**
     * Record a page view. Best-effort: swallows DB errors so a failed write
     * (e.g. disk full) never prevents a donor from seeing the donation form.
     */
    public static function recordPageView(PDO $db): void
    {
        try {
            $db->prepare('INSERT INTO page_views (created_at) VALUES (?)')
               ->execute([time()]);
        } catch (\Throwable $e) {
            error_log('page_view insert failed: ' . $e->getMessage());
        }
    }
}
