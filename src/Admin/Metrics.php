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
 *
 * Every donation query filters by livemode against the admin's currently
 * active Stripe mode (set via the constructor's $isLive flag). Page-view
 * and webhook-heartbeat queries are mode-agnostic because they measure
 * pipeline/traffic health rather than revenue.
 */
final class Metrics
{
    /** Ceiling on any per-request `LIMIT` clause bound through this class. */
    private const MAX_LIMIT = 500;

    /**
     * Per-request memoization. `null` = not yet computed; lets us distinguish
     * "not queried" from a legitimate zero result.
     */
    private ?int   $pageViewCount     = null;
    /** @var ?array{count:int,donors:int,total:int} */
    private ?array $donationAggregate = null;
    private ?float $conversionPercent = null;

    /** 1 (live) | 0 (test) — bound into every donation query. */
    private readonly int $livemode;

    public function __construct(private readonly PDO $db, bool $isLive = true)
    {
        $this->livemode = $isLive ? 1 : 0;
    }

    // ───────────── Public query methods ─────────────

    public function pageViewCount(): int
    {
        return $this->pageViewCount ??=
            (int) $this->db->query('SELECT COUNT(*) FROM page_views')->fetchColumn();
    }

    /**
     * Count of donations that represent real money received. Excludes pending,
     * failed, and refunded rows — this is a revenue view, not an attempt
     * funnel.
     */
    public function donationCount(): int
    {
        return $this->donationAggregate()['count'];
    }

    /** Distinct paid-donor count (email, case-insensitive). */
    public function donorCount(): int
    {
        return $this->donationAggregate()['donors'];
    }

    /** Sum of paid-donation amounts in cents. */
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
     * Most recent donations, newest first.
     *
     * @return list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,interval:?string}>
     */
    public function recentDonations(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, contact_name, email, amount_cents, currency, status,
                    created_at, refunded_at, dedication, "interval"
             FROM donations
             WHERE livemode = :lm
             ORDER BY created_at DESC
             LIMIT :n'
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':n', $this->clampLimit($limit, 100), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $r) => $this->mapRecentRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Fetch a single donation by order_id for the admin detail view. Returns
     * null if no row exists in the current Stripe mode with that id.
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
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $this->mapFullDonationRow($r);
    }

    /**
     * All paid donations in a [from, to) unix-second range, oldest-first so
     * the CSV export streams in chronological order for bookkeeping.
     *
     * @return list<array{order_id:string,payment_intent_id:?string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,email_optin:?bool,interval:?string,stripe_subscription_id:?string,stripe_customer_id:?string}>
     */
    public function donationsInRange(int $fromTs, int $toTs): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency, status,
                    created_at, refunded_at, dedication, email_optin, "interval",
                    stripe_subscription_id, stripe_customer_id
             FROM donations
             WHERE livemode = :lm AND created_at >= :from AND created_at < :to
             ORDER BY created_at ASC'
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':from', $fromTs, PDO::PARAM_INT);
        $stmt->bindValue(':to',   $toTs,   PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn (array $r) => $this->mapFullDonationRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Unix timestamp of the most recently received Stripe webhook event, or
     * null if the server has never successfully processed one.
     *
     * $livemode=null returns the newest event of either mode ("is the pipe
     * open at all?"). $livemode=true/false narrows to that mode — needed
     * because test chatter can mask a broken live pipe otherwise.
     */
    public function lastWebhookAt(?bool $livemode = null): ?int
    {
        if ($livemode === null) {
            $v = $this->db->query('SELECT MAX(received_at) FROM stripe_events')->fetchColumn();
        } else {
            $stmt = $this->db->prepare('SELECT MAX(received_at) FROM stripe_events WHERE livemode = :lm');
            $stmt->bindValue(':lm', $livemode ? 1 : 0, PDO::PARAM_INT);
            $stmt->execute();
            $v = $stmt->fetchColumn();
        }
        return $v === null || $v === false ? null : (int) $v;
    }

    /**
     * Active recurring commitment: for every currently-paid subscription,
     * the most recent amount_cents charged, normalized to a monthly number.
     * Yearly subscriptions divide by 12 for apples-to-apples comparison.
     *
     * "Active" = most recent row's status is `paid`. Correlated subquery
     * (not a window function) because prod SQLite 3.7.17 predates them.
     *
     * @return array{subscriptions:int,monthly_cents:int}
     */
    public function activeRecurringCommitment(): array
    {
        $stmt = $this->db->prepare(
            'SELECT amount_cents, "interval", status
             FROM donations d1
             WHERE livemode = :lm
               AND stripe_subscription_id IS NOT NULL
               AND (subscription_status IS NULL OR subscription_status != \'cancelled\')
               AND created_at = (
                   SELECT MAX(created_at) FROM donations d2
                   WHERE d2.stripe_subscription_id = d1.stripe_subscription_id
                     AND d2.livemode = :lm
               )'
        );
        $this->bindLivemode($stmt);
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
     * Repeat donors — people who have donated 2+ times, ordered by lifetime
     * giving. contact_name uses the most recent donation's name so donors
     * who changed their display get the current spelling.
     *
     * @return list<array{email:string,contact_name:?string,donations:int,total_cents:int,last_at:int}>
     */
    public function repeatDonors(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT lower(email) AS email,
                    COUNT(*)     AS n,
                    SUM(amount_cents) AS total,
                    MAX(created_at)   AS last_at,
                    (SELECT contact_name FROM donations d2
                     WHERE lower(d2.email) = lower(d1.email)
                       AND d2.livemode = :lm AND d2.status = 'paid'
                     ORDER BY d2.created_at DESC LIMIT 1) AS contact_name
             FROM donations d1
             WHERE status = 'paid' AND livemode = :lm
             GROUP BY lower(email)
             HAVING COUNT(*) > 1
             ORDER BY total DESC
             LIMIT :n"
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':n', $this->clampLimit($limit, 100), PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'email'        => (string) $r['email'],
                'contact_name' => self::nullableString($r['contact_name']),
                'donations'    => (int) $r['n'],
                'total_cents'  => (int) $r['total'],
                'last_at'      => (int) $r['last_at'],
            ];
        }
        return $out;
    }

    /**
     * Daily donation totals and counts for the last N days, oldest-first.
     * Missing days are backfilled with zero rows so the sparkline is flat
     * instead of gapped.
     *
     * @return list<array{date:string,count:int,total_cents:int}>
     */
    public function dailyTotalsLast(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $tz = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $end   = (new \DateTimeImmutable('today', $tz))->modify('+1 day'); // exclusive
        $start = $end->modify("-{$days} day");

        // Bucket keys must use the same timezone the PHP side walks below,
        // NOT SQLite's `'localtime'` modifier (which is the server's system
        // tz — typically UTC on managed hosts, producing off-by-one-day keys
        // against APP_TIMEZONE). Pass an explicit signed offset like
        // "-04:00" so SQLite's date() math lines up with DateTimeImmutable
        // in APP_TIMEZONE.
        //
        // Use the offset as of `now` — DST transitions inside the window
        // would shift some bucket boundaries, but for a 30-day dashboard
        // sparkline the one-hour drift on spring-forward / fall-back days
        // is acceptable. A future refinement could bucket per-row by each
        // row's own offset; not worth the complexity today.
        $tzOffset = (new \DateTimeImmutable('now', $tz))->format('P');

        $stmt = $this->db->prepare(
            "SELECT date(created_at, 'unixepoch', :tz_offset) AS d,
                    COUNT(*) AS n,
                    SUM(amount_cents) AS total
             FROM donations
             WHERE livemode = :lm AND status = 'paid'
               AND created_at >= :from AND created_at < :to
             GROUP BY d"
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':from',      $start->getTimestamp(), PDO::PARAM_INT);
        $stmt->bindValue(':to',        $end->getTimestamp(),   PDO::PARAM_INT);
        $stmt->bindValue(':tz_offset', $tzOffset,              PDO::PARAM_STR);
        $stmt->execute();

        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDate[(string) $r['d']] = [
                'count'       => (int) $r['n'],
                'total_cents' => (int) $r['total'],
            ];
        }

        // Backfill zero days so the sparkline has constant shape.
        $out = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $out[] = [
                'date'        => $key,
                'count'       => $byDate[$key]['count']       ?? 0,
                'total_cents' => $byDate[$key]['total_cents'] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Refund rate over the last N days as a percentage 0..100. Numerator is
     * rows refunded in the window; denominator is rows created in the same
     * window. Answers "is fraud/friction spiking recently?", not "cumulative
     * refund rate ever."
     *
     * @return array{donations:int,refunded:int,rate_pct:float}
     */
    public function refundRateLast(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $from = time() - ($days * 86400);

        $stmt = $this->db->prepare(
            'SELECT
                SUM(CASE WHEN created_at >= :from THEN 1 ELSE 0 END) AS donations,
                SUM(CASE WHEN refunded_at IS NOT NULL AND refunded_at >= :from THEN 1 ELSE 0 END) AS refunded
             FROM donations WHERE livemode = :lm'
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':from', $from, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['donations' => 0, 'refunded' => 0];

        $donations = (int) ($row['donations'] ?? 0);
        $refunded  = (int) ($row['refunded']  ?? 0);
        $rate      = $donations === 0 ? 0.0 : round(($refunded / $donations) * 100, 1);
        return ['donations' => $donations, 'refunded' => $refunded, 'rate_pct' => $rate];
    }

    /**
     * Paginated, filterable transactions list. All statuses, not just paid.
     *
     * @param array{email?:string,status?:string,from_ts?:?int,to_ts?:?int,limit:int,offset:int} $f
     * @return list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,interval:?string,created_at:int,refunded_at:?int,dedication:?string,stripe_subscription_id:?string}>
     */
    public function listTransactions(array $f): array
    {
        [$where, $params] = $this->buildTxnWhere($f);
        $stmt = $this->db->prepare(
            "SELECT order_id, contact_name, email, amount_cents, currency, status, \"interval\",
                    created_at, refunded_at, dedication, stripe_subscription_id
             FROM donations {$where}
             ORDER BY created_at DESC
             LIMIT :lim OFFSET :off"
        );
        $this->bindFilterParams($stmt, $params);
        $stmt->bindValue(':lim', $this->clampLimit((int) $f['limit']), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, (int) $f['offset']),           PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'order_id'               => (string) $r['order_id'],
                'contact_name'           => self::nullableString($r['contact_name']),
                'email'                  => (string) $r['email'],
                'amount_cents'           => (int)    $r['amount_cents'],
                'currency'               => (string) $r['currency'],
                'status'                 => (string) $r['status'],
                'interval'               => self::nullableString($r['interval']),
                'created_at'             => (int)    $r['created_at'],
                'refunded_at'            => self::nullableInt($r['refunded_at']),
                'dedication'             => self::nullableString($r['dedication']),
                'stripe_subscription_id' => self::nullableString($r['stripe_subscription_id']),
            ];
        }
        return $out;
    }

    /** Row count matching listTransactions filters, for pagination totals. */
    public function countTransactions(array $f): int
    {
        [$where, $params] = $this->buildTxnWhere($f);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM donations {$where}");
        $this->bindFilterParams($stmt, $params);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Subscriptions index — one row per stripe_subscription_id with
     * aggregated totals + the most recent row's donor fields.
     *
     * @return list<array{stripe_subscription_id:string,stripe_customer_id:?string,contact_name:?string,email:string,interval:?string,amount_cents:int,invoices:int,total_cents:int,last_at:int,status:string}>
     */
    public function listSubscriptions(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT stripe_subscription_id AS sub_id,
                    SUM(amount_cents)      AS total,
                    COUNT(*)               AS invoices,
                    MAX(created_at)        AS last_at
             FROM donations
             WHERE livemode = :lm AND stripe_subscription_id IS NOT NULL
             GROUP BY stripe_subscription_id
             ORDER BY last_at DESC
             LIMIT :lim OFFSET :off'
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':lim', $this->clampLimit($limit), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset),           PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $latestStmt = $this->db->prepare(
            'SELECT contact_name, email, "interval", amount_cents, status, stripe_customer_id
             FROM donations
             WHERE stripe_subscription_id = :sid AND livemode = :lm
             ORDER BY created_at DESC LIMIT 1'
        );

        $out = [];
        foreach ($rows as $r) {
            $latestStmt->execute([':sid' => $r['sub_id'], ':lm' => $this->livemode]);
            $latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out[] = [
                'stripe_subscription_id' => (string) $r['sub_id'],
                'stripe_customer_id'     => self::nullableString($latest['stripe_customer_id'] ?? null),
                'contact_name'           => self::nullableString($latest['contact_name']       ?? null),
                'email'                  => (string) ($latest['email']          ?? ''),
                'interval'               => self::nullableString($latest['interval']           ?? null),
                'amount_cents'           => (int)    ($latest['amount_cents']   ?? 0),
                'invoices'               => (int)    $r['invoices'],
                'total_cents'            => (int)    $r['total'],
                'last_at'                => (int)    $r['last_at'],
                'status'                 => (string) ($latest['status']         ?? 'unknown'),
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
        $this->bindLivemode($stmt);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * All invoice rows for a single subscription, newest-first. Used by the
     * subscription detail page.
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
        $this->bindLivemode($stmt);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'order_id'          => (string) $r['order_id'],
                'payment_intent_id' => self::nullableString($r['payment_intent_id']),
                'amount_cents'      => (int)    $r['amount_cents'],
                'currency'          => (string) $r['currency'],
                'status'            => (string) $r['status'],
                'created_at'        => (int)    $r['created_at'],
                'refunded_at'       => self::nullableInt($r['refunded_at']),
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
                    MIN(created_at)   AS first_at,
                    MAX(created_at)   AS last_at,
                    (SELECT contact_name FROM donations d2
                     WHERE lower(d2.email) = lower(d1.email)
                       AND d2.livemode = :lm AND d2.status = 'paid'
                     ORDER BY d2.created_at DESC LIMIT 1) AS contact_name
             FROM donations d1
             WHERE status = 'paid' AND livemode = :lm
             GROUP BY lower(email)
             ORDER BY total DESC
             LIMIT :lim OFFSET :off"
        );
        $this->bindLivemode($stmt);
        $stmt->bindValue(':lim', $this->clampLimit($limit), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset),           PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'email'        => (string) $r['email'],
                'contact_name' => self::nullableString($r['contact_name']),
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
        $this->bindLivemode($stmt);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Donor detail aggregate — everything a single donor has done. Keyed by
     * a SHA-256 hash of the lowercased email so email identifiers don't
     * land in URLs, logs, or browser history.
     *
     * @return ?array{email:string,contact_name:?string,total_cents:int,donations:list<array<string,mixed>>,subscriptions:list<string>,last_optin:?bool}
     */
    public function findDonorByEmailHash(string $emailHashHex): ?array
    {
        $email = $this->resolveEmailFromHash($emailHashHex);
        if ($email === null) {
            return null;
        }

        $rows = $this->fetchDonorRows($email);
        if ($rows === []) {
            return null;
        }

        return $this->buildDonorAggregate($email, $rows);
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

    // ───────────── Private helpers ─────────────

    /**
     * Single query that returns count, distinct-donor count, and total cents
     * for paid donations. Three scalar dashboard metrics share it so the
     * render produces one full-table scan instead of three.
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
        $this->bindLivemode($stmt);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'd' => 0, 't' => 0];

        return $this->donationAggregate = [
            'count'  => (int) $row['c'],
            'donors' => (int) $row['d'],
            'total'  => (int) $row['t'],
        ];
    }

    /**
     * Build the WHERE clause and bound parameters for the transactions list
     * queries. Shared by {@see listTransactions} and {@see countTransactions}
     * so filter logic can't diverge.
     *
     * @return array{0:string,1:array<string,array{0:mixed,1:int}>}
     */
    private function buildTxnWhere(array $f): array
    {
        $conds  = ['livemode = :lm'];
        $params = [':lm' => [$this->livemode, PDO::PARAM_INT]];

        $email = isset($f['email']) ? trim((string) $f['email']) : '';
        if ($email !== '') {
            $conds[]          = 'lower(email) LIKE :email';
            $params[':email'] = ['%' . strtolower($email) . '%', PDO::PARAM_STR];
        }
        $status = isset($f['status']) ? (string) $f['status'] : '';
        if (in_array($status, ['paid', 'refunded', 'cancelled'], true)) {
            $conds[]           = 'status = :status';
            $params[':status'] = [$status, PDO::PARAM_STR];
        }
        if (!empty($f['from_ts'])) {
            $conds[]            = 'created_at >= :from_ts';
            $params[':from_ts'] = [(int) $f['from_ts'], PDO::PARAM_INT];
        }
        if (!empty($f['to_ts'])) {
            $conds[]          = 'created_at < :to_ts';
            $params[':to_ts'] = [(int) $f['to_ts'], PDO::PARAM_INT];
        }
        return ['WHERE ' . implode(' AND ', $conds), $params];
    }

    /** Bind every filter param produced by buildTxnWhere onto a prepared stmt. */
    private function bindFilterParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
    }

    /** Bind `:lm` = current livemode flag (0|1) on a prepared statement. */
    private function bindLivemode(\PDOStatement $stmt): void
    {
        $stmt->bindValue(':lm', $this->livemode, PDO::PARAM_INT);
    }

    /** Clamp a caller-supplied `LIMIT` to [1, $ceiling]. */
    private function clampLimit(int $limit, int $ceiling = self::MAX_LIMIT): int
    {
        return max(1, min($ceiling, $limit));
    }

    /**
     * Map a full-shape donations row (every selected column) to the donation
     * detail / CSV export / donor-detail array shape. Kept in one place so
     * the four callers can't drift apart.
     *
     * @param  array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function mapFullDonationRow(array $r): array
    {
        return [
            'order_id'               => (string) $r['order_id'],
            'payment_intent_id'      => self::nullableString($r['payment_intent_id'] ?? null),
            'contact_name'           => self::nullableString($r['contact_name']      ?? null),
            'email'                  => (string) ($r['email'] ?? ''),
            'amount_cents'           => (int)    $r['amount_cents'],
            'currency'               => (string) $r['currency'],
            'status'                 => (string) $r['status'],
            'created_at'             => (int)    $r['created_at'],
            'refunded_at'            => self::nullableInt($r['refunded_at']           ?? null),
            'dedication'             => self::nullableString($r['dedication']         ?? null),
            'email_optin'            => self::nullableBoolFromInt($r['email_optin']   ?? null),
            'interval'               => self::nullableString($r['interval']           ?? null),
            'stripe_subscription_id' => self::nullableString($r['stripe_subscription_id'] ?? null),
            'stripe_customer_id'     => self::nullableString($r['stripe_customer_id'] ?? null),
        ];
    }

    /**
     * Map a recent-donations row (subset of columns) to the dashboard
     * list shape. Does not include payment_intent_id, email_optin, or
     * subscription/customer ids — those are only fetched by the detail
     * query, not the dashboard.
     *
     * @param  array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function mapRecentRow(array $r): array
    {
        return [
            'order_id'     => (string) $r['order_id'],
            'contact_name' => self::nullableString($r['contact_name']),
            'email'        => (string) $r['email'],
            'amount_cents' => (int)    $r['amount_cents'],
            'currency'     => (string) $r['currency'],
            'status'       => (string) $r['status'],
            'created_at'   => (int)    $r['created_at'],
            'refunded_at'  => self::nullableInt($r['refunded_at']),
            'dedication'   => self::nullableString($r['dedication']),
            'interval'     => self::nullableString($r['interval']),
        ];
    }

    /**
     * Find the lowercased email whose SHA-256 matches the URL hash. Small N
     * (one entry per distinct donor), so a linear scan with constant-time
     * compare is fine and avoids storing a reverse index.
     */
    private function resolveEmailFromHash(string $emailHashHex): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT lower(email) AS email
             FROM donations WHERE livemode = :lm AND status IN ('paid','refunded','cancelled')"
        );
        $this->bindLivemode($stmt);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
            if (hash_equals($emailHashHex, hash('sha256', (string) $candidate))) {
                return (string) $candidate;
            }
        }
        return null;
    }

    /**
     * @return list<array<string,mixed>>  Raw rows, newest first.
     */
    private function fetchDonorRows(string $email): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency,
                    status, created_at, refunded_at, dedication, email_optin, "interval",
                    stripe_subscription_id, stripe_customer_id
             FROM donations
             WHERE lower(email) = :email AND livemode = :lm
             ORDER BY created_at DESC'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $this->bindLivemode($stmt);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Walk donor rows (already newest-first) and derive the aggregate shape
     * the donor detail template consumes.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function buildDonorAggregate(string $email, array $rows): array
    {
        $donations   = [];
        $total       = 0;
        $subs        = [];
        $contactName = null;
        $lastOptin   = null;

        foreach ($rows as $r) {
            if ($contactName === null && self::nullableString($r['contact_name']) !== null) {
                $contactName = (string) $r['contact_name']; // newest-first, first non-null wins
            }
            if ($lastOptin === null && self::nullableBoolFromInt($r['email_optin']) !== null) {
                $lastOptin = ((int) $r['email_optin']) === 1;
            }
            if ((string) $r['status'] === 'paid') {
                $total += (int) $r['amount_cents'];
            }
            $sid = self::nullableString($r['stripe_subscription_id']);
            if ($sid !== null && !in_array($sid, $subs, true)) {
                $subs[] = $sid;
            }
            $donations[] = $this->mapFullDonationRow($r);
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

    // ───────────── Value coercion helpers ─────────────

    /** Coerce a DB value to `?string`, preserving null / '' semantics. */
    private static function nullableString(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    /** Coerce a DB value to `?int`, preserving null semantics. */
    private static function nullableInt(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /** Map a DB `INTEGER` opt-in flag (0|1|null) to `?bool`. */
    private static function nullableBoolFromInt(mixed $v): ?bool
    {
        return $v === null ? null : ((int) $v === 1);
    }
}
