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

    public function __construct(private readonly PDO $db) {}

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
        $row = $this->db->query(
            "SELECT COUNT(*) AS c, COUNT(DISTINCT lower(email)) AS d, COALESCE(SUM(amount_cents), 0) AS t
             FROM donations WHERE status = 'paid'"
        )->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'd' => 0, 't' => 0];

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
     * @return list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string}>
     */
    public function recentDonations(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, contact_name, email, amount_cents, currency, status, created_at, refunded_at, dedication
             FROM donations
             ORDER BY created_at DESC
             LIMIT :n'
        );
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
            ];
        }
        return $out;
    }

    /**
     * Fetch a single donation by order_id for the admin detail view.
     *
     * @return ?array{order_id:string,payment_intent_id:?string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,email_optin:?bool}
     */
    public function findDonation(string $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency, status,
                    created_at, refunded_at, dedication, email_optin
             FROM donations WHERE order_id = :oid'
        );
        $stmt->execute([':oid' => $orderId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }
        return [
            'order_id'          => (string) $r['order_id'],
            'payment_intent_id' => $r['payment_intent_id'] !== null ? (string) $r['payment_intent_id'] : null,
            'contact_name'      => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
            'email'             => (string) $r['email'],
            'amount_cents'      => (int)    $r['amount_cents'],
            'currency'          => (string) $r['currency'],
            'status'            => (string) $r['status'],
            'created_at'        => (int)    $r['created_at'],
            'refunded_at'       => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
            'dedication'        => $r['dedication'] !== null ? (string) $r['dedication'] : null,
            'email_optin'       => $r['email_optin'] !== null ? ((int) $r['email_optin'] === 1) : null,
        ];
    }

    /**
     * All paid donations in a [from, to) unix-second range, oldest-first so
     * the CSV export streams in chronological order for bookkeeping.
     *
     * @return list<array{order_id:string,payment_intent_id:?string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int,dedication:?string,email_optin:?bool}>
     */
    public function donationsInRange(int $fromTs, int $toTs): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, payment_intent_id, contact_name, email, amount_cents, currency, status,
                    created_at, refunded_at, dedication, email_optin
             FROM donations
             WHERE created_at >= :from AND created_at < :to
             ORDER BY created_at ASC'
        );
        $stmt->bindValue(':from', $fromTs, PDO::PARAM_INT);
        $stmt->bindValue(':to',   $toTs,   PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'order_id'          => (string) $r['order_id'],
                'payment_intent_id' => $r['payment_intent_id'] !== null ? (string) $r['payment_intent_id'] : null,
                'contact_name'      => $r['contact_name'] !== null ? (string) $r['contact_name'] : null,
                'email'             => (string) $r['email'],
                'amount_cents'      => (int)    $r['amount_cents'],
                'currency'          => (string) $r['currency'],
                'status'            => (string) $r['status'],
                'created_at'        => (int)    $r['created_at'],
                'refunded_at'       => $r['refunded_at'] !== null ? (int) $r['refunded_at'] : null,
                'dedication'        => $r['dedication'] !== null ? (string) $r['dedication'] : null,
                'email_optin'       => $r['email_optin'] !== null ? ((int) $r['email_optin'] === 1) : null,
            ];
        }
        return $out;
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
