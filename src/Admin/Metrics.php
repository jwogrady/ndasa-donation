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
    private ?int   $donationCount     = null;
    private ?int   $donorCount        = null;
    private ?int   $totalDonationCents = null;
    private ?float $conversionPercent = null;

    public function __construct(private readonly PDO $db) {}

    public function pageViewCount(): int
    {
        return $this->pageViewCount ??=
            (int) $this->db->query('SELECT COUNT(*) FROM page_views')->fetchColumn();
    }

    /**
     * Count of donations that represent real money received.
     * Excludes pending, failed, and refunded rows — the dashboard is a
     * revenue view, not an attempt-funnel view.
     */
    public function donationCount(): int
    {
        return $this->donationCount ??=
            (int) $this->db->query("SELECT COUNT(*) FROM donations WHERE status = 'paid'")->fetchColumn();
    }

    /** Distinct donor count among successful donations (by email, case-insensitive). */
    public function donorCount(): int
    {
        return $this->donorCount ??= (int) $this->db
            ->query("SELECT COUNT(DISTINCT lower(email)) FROM donations WHERE status = 'paid'")
            ->fetchColumn();
    }

    /** Sum of donation amounts in cents, restricted to paid (non-refunded) rows. */
    public function totalDonationCents(): int
    {
        return $this->totalDonationCents ??=
            (int) $this->db
                ->query("SELECT COALESCE(SUM(amount_cents), 0) FROM donations WHERE status = 'paid'")
                ->fetchColumn();
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
     * @return list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int}>
     */
    public function recentDonations(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT order_id, contact_name, email, amount_cents, currency, status, created_at, refunded_at
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
