<?php
declare(strict_types=1);

namespace NDASA\Tests\Admin;

use NDASA\Admin\Metrics;
use NDASA\Tests\Support\DatabaseTestCase;
use NDASA\Webhook\EventStore;

final class MetricsTest extends DatabaseTestCase
{
    /** Test-mode Metrics instance — covers livemode=0 filtering by default. */
    private Metrics $metrics;
    private EventStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store   = new EventStore($this->db);
        $this->metrics = new Metrics($this->db, isLive: false);
    }

    public function test_zero_state_returns_zeros_and_no_rows(): void
    {
        $this->assertSame(0,    $this->metrics->donationCount());
        $this->assertSame(0,    $this->metrics->donorCount());
        $this->assertSame(0,    $this->metrics->totalDonationCents());
        $this->assertSame(0,    $this->metrics->pageViewCount());
        $this->assertSame(0.0,  $this->metrics->conversionRatePercent());
        $this->assertSame([],   $this->metrics->recentDonations(10));
        $this->assertNull($this->metrics->lastWebhookAt());
    }

    public function test_aggregates_filter_by_livemode(): void
    {
        // Two test-mode paid donations, one live-mode row that must be ignored.
        $this->seed('ord_t1', ['livemode' => false, 'amount_cents' => 1000, 'email' => 'a@x.com']);
        $this->seed('ord_t2', ['livemode' => false, 'amount_cents' => 2000, 'email' => 'b@x.com']);
        $this->seed('ord_L',  ['livemode' => true,  'amount_cents' => 9999, 'email' => 'c@x.com']);

        $this->assertSame(2,    $this->metrics->donationCount());
        $this->assertSame(2,    $this->metrics->donorCount());
        $this->assertSame(3000, $this->metrics->totalDonationCents());
    }

    public function test_donor_count_deduplicates_by_lowercased_email(): void
    {
        $this->seed('ord_1', ['email' => 'Jane@X.com', 'livemode' => false]);
        $this->seed('ord_2', ['email' => 'jane@x.com', 'livemode' => false]);
        $this->assertSame(1, $this->metrics->donorCount());
    }

    public function test_refunded_rows_are_excluded_from_revenue_aggregates(): void
    {
        $this->seed('ord_paid', ['amount_cents' => 1000, 'status' => 'paid',     'livemode' => false]);
        $this->seed('ord_ref',  ['amount_cents' => 5000, 'status' => 'refunded', 'livemode' => false]);
        $this->assertSame(1,    $this->metrics->donationCount());
        $this->assertSame(1000, $this->metrics->totalDonationCents());
    }

    public function test_conversion_rate_is_zero_when_no_page_views(): void
    {
        $this->seed('ord_x', ['livemode' => false]);
        $this->assertSame(0.0, $this->metrics->conversionRatePercent());
    }

    public function test_conversion_rate_rounds_to_one_decimal(): void
    {
        $this->seed('ord_a', ['livemode' => false]);
        // 1 donation / 3 views = 33.333… → 33.3
        for ($i = 0; $i < 3; $i++) {
            $this->db->exec('INSERT INTO page_views (created_at) VALUES (' . time() . ')');
        }
        $fresh = new Metrics($this->db, isLive: false); // conversion is memoised
        $this->assertSame(33.3, $fresh->conversionRatePercent());
    }

    public function test_recentDonations_returns_newest_first(): void
    {
        $this->seed('ord_old', ['created_at' => 1000, 'livemode' => false]);
        $this->seed('ord_new', ['created_at' => 2000, 'livemode' => false]);
        $rows = $this->metrics->recentDonations(10);
        $this->assertSame('ord_new', $rows[0]['order_id']);
        $this->assertSame('ord_old', $rows[1]['order_id']);
    }

    public function test_recentDonations_clamps_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seed('ord_' . $i, ['created_at' => $i, 'livemode' => false]);
        }
        // limit=0 must not return 0 rows — clamped to 1.
        $this->assertCount(1, $this->metrics->recentDonations(0));
        $this->assertCount(3, $this->metrics->recentDonations(10));
    }

    // ───────────── findDonation ─────────────

    public function test_findDonation_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->metrics->findDonation('no_such_order'));
    }

    public function test_findDonation_returns_null_across_modes(): void
    {
        // Row exists in LIVE mode, but we're querying in TEST mode.
        $this->seed('ord_live_only', ['livemode' => true]);
        $this->assertNull($this->metrics->findDonation('ord_live_only'));
    }

    public function test_findDonation_hydrates_full_shape(): void
    {
        $this->seed('ord_full', [
            'livemode'     => false,
            'dedication'   => 'In honor',
            'email_optin'  => 1,
            'interval'     => 'month',
        ]);
        $row = $this->metrics->findDonation('ord_full');
        $this->assertNotNull($row);
        $this->assertSame('In honor', $row['dedication']);
        $this->assertTrue($row['email_optin']);
        $this->assertSame('month',    $row['interval']);
    }

    // ───────────── listTransactions / countTransactions ─────────────

    public function test_listTransactions_applies_email_and_status_filters(): void
    {
        $this->seed('ord_jane_paid', ['email' => 'jane@x.com', 'status' => 'paid',     'livemode' => false]);
        $this->seed('ord_jane_refd', ['email' => 'jane@x.com', 'status' => 'refunded', 'livemode' => false]);
        $this->seed('ord_bob_paid',  ['email' => 'bob@x.com',  'status' => 'paid',     'livemode' => false]);

        $paidJane = $this->metrics->countTransactions([
            'email' => 'jane', 'status' => 'paid', 'limit' => 25, 'offset' => 0,
        ]);
        $this->assertSame(1, $paidJane);

        $anyJane = $this->metrics->countTransactions([
            'email' => 'jane', 'status' => '', 'limit' => 25, 'offset' => 0,
        ]);
        $this->assertSame(2, $anyJane);

        $allLive = $this->metrics->countTransactions(['limit' => 25, 'offset' => 0]);
        $this->assertSame(3, $allLive);
    }

    public function test_listTransactions_applies_date_range(): void
    {
        $this->seed('ord_before', ['created_at' => 1000, 'livemode' => false]);
        $this->seed('ord_inside', ['created_at' => 2000, 'livemode' => false]);
        $this->seed('ord_after',  ['created_at' => 3000, 'livemode' => false]);

        $rows = $this->metrics->listTransactions([
            'from_ts' => 1500,
            'to_ts'   => 2500,
            'limit'   => 25,
            'offset'  => 0,
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('ord_inside', $rows[0]['order_id']);
    }

    public function test_listTransactions_ignores_unknown_status(): void
    {
        // status="weird" is not one of paid/refunded/cancelled, so the filter
        // is silently dropped instead of matching zero rows. Safer than 500ing.
        $this->seed('ord_p', ['status' => 'paid', 'livemode' => false]);
        $n = $this->metrics->countTransactions([
            'status' => 'weird', 'limit' => 25, 'offset' => 0,
        ]);
        $this->assertSame(1, $n);
    }

    // ───────────── activeRecurringCommitment ─────────────

    public function test_activeRecurring_sums_paid_subscriptions(): void
    {
        $this->seed('ord_m1', [
            'stripe_subscription_id' => 'sub_m', 'interval' => 'month',
            'amount_cents' => 1000, 'status' => 'paid', 'livemode' => false,
        ]);
        $this->seed('ord_y1', [
            'stripe_subscription_id' => 'sub_y', 'interval' => 'year',
            'amount_cents' => 12000, 'status' => 'paid', 'livemode' => false,
        ]);

        $result = $this->metrics->activeRecurringCommitment();
        // 1000 monthly + (12000 / 12) yearly = 2000
        $this->assertSame(2, $result['subscriptions']);
        $this->assertSame(2000, $result['monthly_cents']);
    }

    public function test_activeRecurring_uses_most_recent_row_per_subscription(): void
    {
        // Two invoices for the same sub. The older is paid, the newer is
        // refunded → subscription counts as inactive.
        $this->seed('inv_old', [
            'stripe_subscription_id' => 'sub_x', 'interval' => 'month',
            'amount_cents' => 1000, 'status' => 'paid', 'livemode' => false,
            'created_at'   => 100,
        ]);
        $this->seed('inv_new', [
            'stripe_subscription_id' => 'sub_x', 'interval' => 'month',
            'amount_cents' => 1000, 'status' => 'refunded', 'livemode' => false,
            'created_at'   => 200,
        ]);

        $result = $this->metrics->activeRecurringCommitment();
        $this->assertSame(0,  $result['subscriptions']);
        $this->assertSame(0,  $result['monthly_cents']);
    }

    public function test_activeRecurring_excludes_cancelled_subscriptions(): void
    {
        // Paid monthly sub whose subscription_status has been flipped to
        // 'cancelled' (customer.subscription.deleted fired). Historical rows
        // stay paid — but the dashboard must stop counting it as active.
        $this->seed('inv_cancelled', [
            'stripe_subscription_id' => 'sub_gone', 'interval' => 'month',
            'amount_cents' => 2500, 'status' => 'paid', 'livemode' => false,
            'subscription_status' => 'cancelled',
        ]);
        // A live-running sub in the same mode for contrast.
        $this->seed('inv_running', [
            'stripe_subscription_id' => 'sub_ok', 'interval' => 'month',
            'amount_cents' => 1500, 'status' => 'paid', 'livemode' => false,
        ]);

        $result = $this->metrics->activeRecurringCommitment();
        $this->assertSame(1,    $result['subscriptions']);
        $this->assertSame(1500, $result['monthly_cents']);
    }

    public function test_activeRecurring_filters_cancelled_independently_in_live_and_test(): void
    {
        // Same data shape in both modes to prove the subscription_status
        // exclusion is applied per-mode, not globally.
        $this->seed('live_cancel', [
            'stripe_subscription_id' => 'sub_live_gone', 'interval' => 'month',
            'amount_cents' => 5000, 'status' => 'paid', 'livemode' => true,
            'subscription_status' => 'cancelled',
        ]);
        $this->seed('live_running', [
            'stripe_subscription_id' => 'sub_live_ok', 'interval' => 'month',
            'amount_cents' => 3000, 'status' => 'paid', 'livemode' => true,
        ]);
        $this->seed('test_cancel', [
            'stripe_subscription_id' => 'sub_test_gone', 'interval' => 'month',
            'amount_cents' => 5000, 'status' => 'paid', 'livemode' => false,
            'subscription_status' => 'cancelled',
        ]);
        $this->seed('test_running', [
            'stripe_subscription_id' => 'sub_test_ok', 'interval' => 'month',
            'amount_cents' => 2000, 'status' => 'paid', 'livemode' => false,
        ]);

        $testMode = new Metrics($this->db, isLive: false);
        $liveMode = new Metrics($this->db, isLive: true);

        $this->assertSame(['subscriptions' => 1, 'monthly_cents' => 2000], $testMode->activeRecurringCommitment());
        $this->assertSame(['subscriptions' => 1, 'monthly_cents' => 3000], $liveMode->activeRecurringCommitment());
    }

    // ───────────── repeatDonors + listDonors ─────────────

    public function test_repeatDonors_returns_only_2plus_gifts(): void
    {
        $this->seed('ord_a1', ['email' => 'alice@x.com', 'amount_cents' => 500,  'livemode' => false]);
        $this->seed('ord_a2', ['email' => 'alice@x.com', 'amount_cents' => 1500, 'livemode' => false]);
        $this->seed('ord_b1', ['email' => 'bob@x.com',   'amount_cents' => 1000, 'livemode' => false]);

        $rows = $this->metrics->repeatDonors(10);
        $this->assertCount(1, $rows);
        $this->assertSame('alice@x.com', $rows[0]['email']);
        $this->assertSame(2,             $rows[0]['donations']);
        $this->assertSame(2000,          $rows[0]['total_cents']);
    }

    public function test_listDonors_orders_by_lifetime_desc(): void
    {
        $this->seed('ord_small', ['email' => 'small@x.com', 'amount_cents' => 500,  'livemode' => false]);
        $this->seed('ord_big',   ['email' => 'big@x.com',   'amount_cents' => 5000, 'livemode' => false]);
        $rows = $this->metrics->listDonors(10, 0);
        $this->assertSame('big@x.com',   $rows[0]['email']);
        $this->assertSame('small@x.com', $rows[1]['email']);
    }

    public function test_findDonorByEmailHash_matches_lowercased_email(): void
    {
        $this->seed('ord_a', ['email' => 'Jane@X.com', 'livemode' => false]);
        $hash = hash('sha256', 'jane@x.com');
        $donor = $this->metrics->findDonorByEmailHash($hash);
        $this->assertNotNull($donor);
        $this->assertSame('jane@x.com', $donor['email']);
    }

    public function test_findDonorByEmailHash_returns_null_for_unknown_hash(): void
    {
        $this->assertNull($this->metrics->findDonorByEmailHash(str_repeat('a', 64)));
    }

    // ───────────── refundRateLast / dailyTotalsLast ─────────────

    public function test_refundRate_reports_zero_when_no_donations(): void
    {
        $result = $this->metrics->refundRateLast(30);
        $this->assertSame(0,   $result['donations']);
        $this->assertSame(0,   $result['refunded']);
        $this->assertSame(0.0, $result['rate_pct']);
    }

    public function test_refundRate_computes_percentage_in_window(): void
    {
        $now = time();
        // 10 recent donations, 1 recent refund → 10% refund rate.
        for ($i = 0; $i < 10; $i++) {
            $this->seed('ord_r_' . $i, [
                'livemode'   => false,
                'created_at' => $now - 100,
            ]);
        }
        // Flag one of them as refunded inside the same window.
        $this->db->exec("UPDATE donations SET status='refunded', refunded_at=" . ($now - 50) . " WHERE order_id='ord_r_0'");

        $result = $this->metrics->refundRateLast(30);
        $this->assertSame(10,   $result['donations']);
        $this->assertSame(1,    $result['refunded']);
        $this->assertSame(10.0, $result['rate_pct']);
    }

    public function test_dailyTotalsLast_returns_full_window_with_zero_backfill(): void
    {
        $days = $this->metrics->dailyTotalsLast(7);
        $this->assertCount(7, $days);
        foreach ($days as $d) {
            $this->assertSame(0, $d['count']);
            $this->assertSame(0, $d['total_cents']);
        }
    }

    // ───────────── Helpers ─────────────

    /** @param array<string,mixed> $overrides */
    private function seed(string $orderId, array $overrides = []): void
    {
        $row = array_replace([
            'order_id'               => $orderId,
            'payment_intent_id'      => 'pi_' . $orderId,
            'amount_cents'           => 2500,
            'currency'               => 'usd',
            'email'                  => 'default@x.com',
            'contact_name'           => 'Default',
            'status'                 => 'paid',
            'created_at'             => time(),
            'refunded_at'            => null,
            'dedication'             => null,
            'email_optin'            => null,
            'interval'               => null,
            'stripe_subscription_id' => null,
            'stripe_customer_id'     => null,
            'livemode'               => true,
            'subscription_status'    => null,
        ], $overrides);

        $this->db->prepare('INSERT INTO donations
            (order_id, payment_intent_id, amount_cents, currency, email, contact_name,
             status, created_at, refunded_at, dedication, email_optin, "interval",
             stripe_subscription_id, stripe_customer_id, livemode, subscription_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $row['order_id'],
                $row['payment_intent_id'],
                $row['amount_cents'],
                $row['currency'],
                $row['email'],
                $row['contact_name'],
                $row['status'],
                $row['created_at'],
                $row['refunded_at'],
                $row['dedication'],
                $row['email_optin'],
                $row['interval'],
                $row['stripe_subscription_id'],
                $row['stripe_customer_id'],
                $row['livemode'] ? 1 : 0,
                $row['subscription_status'],
            ]);
    }
}
