<?php
declare(strict_types=1);

namespace NDASA\Tests\Webhook;

use NDASA\Tests\Support\DatabaseTestCase;
use NDASA\Webhook\EventStore;

final class EventStoreTest extends DatabaseTestCase
{
    private EventStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new EventStore($this->db);
    }

    public function test_isProcessed_returns_false_for_unseen_event(): void
    {
        $this->assertFalse($this->store->isProcessed('evt_never_seen'));
    }

    public function test_markProcessed_returns_true_on_first_insert(): void
    {
        $this->assertTrue($this->store->markProcessed('evt_1', 'checkout.session.completed'));
        $this->assertTrue($this->store->isProcessed('evt_1'));
    }

    public function test_markProcessed_is_idempotent(): void
    {
        $this->assertTrue($this->store->markProcessed('evt_1', 'checkout.session.completed'));
        // Second mark with the same event id returns false — row already existed.
        $this->assertFalse($this->store->markProcessed('evt_1', 'checkout.session.completed'));
        $this->assertSame(1, $this->countRows('stripe_events'));
    }

    public function test_recordDonation_writes_all_fields(): void
    {
        $this->store->recordDonation([
            'order_id'               => 'ord_abc',
            'payment_intent_id'      => 'pi_abc',
            'amount_cents'           => 5000,
            'currency'               => 'usd',
            'email'                  => 'donor@example.com',
            'contact_name'           => 'Test Donor',
            'status'                 => 'paid',
            'dedication'             => 'In memory of X',
            'email_optin'            => true,
            'interval'               => 'month',
            'stripe_subscription_id' => 'sub_xyz',
            'stripe_customer_id'     => 'cus_xyz',
            'livemode'               => true,
        ]);

        $row = $this->findDonationRow('ord_abc');
        $this->assertNotNull($row);
        $this->assertSame('pi_abc',            $row['payment_intent_id']);
        $this->assertSame(5000,                (int) $row['amount_cents']);
        $this->assertSame('usd',               $row['currency']);
        $this->assertSame('donor@example.com', $row['email']);
        $this->assertSame('Test Donor',        $row['contact_name']);
        $this->assertSame('paid',              $row['status']);
        $this->assertSame('In memory of X',    $row['dedication']);
        $this->assertSame(1,                   (int) $row['email_optin']);
        $this->assertSame('month',             $row['interval']);
        $this->assertSame('sub_xyz',           $row['stripe_subscription_id']);
        $this->assertSame('cus_xyz',           $row['stripe_customer_id']);
        $this->assertSame(1,                   (int) $row['livemode']);
    }

    public function test_recordDonation_normalizes_once_interval_to_null(): void
    {
        $this->store->recordDonation([
            'order_id'          => 'ord_once',
            'payment_intent_id' => 'pi_once',
            'amount_cents'      => 2500,
            'currency'          => 'usd',
            'email'             => 'a@b.com',
            'contact_name'      => 'A',
            'status'            => 'paid',
            'interval'          => 'once',
            'livemode'          => true,
        ]);
        $this->assertNull($this->findDonationRow('ord_once')['interval']);
    }

    public function test_recordDonation_normalizes_empty_dedication_to_null(): void
    {
        $this->store->recordDonation($this->baseDonation('ord_ded', ['dedication' => '']));
        $this->assertNull($this->findDonationRow('ord_ded')['dedication']);
    }

    public function test_recordDonation_normalizes_empty_subscription_id_to_null(): void
    {
        $this->store->recordDonation($this->baseDonation('ord_sub', [
            'stripe_subscription_id' => '',
        ]));
        $this->assertNull($this->findDonationRow('ord_sub')['stripe_subscription_id']);
    }

    public function test_recordDonation_defaults_livemode_to_1(): void
    {
        $d = $this->baseDonation('ord_default_lm');
        unset($d['livemode']);
        $this->store->recordDonation($d);
        $this->assertSame(1, (int) $this->findDonationRow('ord_default_lm')['livemode']);
    }

    public function test_recordDonation_writes_livemode_0_for_test_event(): void
    {
        $this->store->recordDonation($this->baseDonation('ord_test', ['livemode' => false]));
        $this->assertSame(0, (int) $this->findDonationRow('ord_test')['livemode']);
    }

    public function test_recordDonation_is_idempotent_on_order_id(): void
    {
        $this->store->recordDonation($this->baseDonation('ord_dupe', ['amount_cents' => 1000]));
        // Second insert with the same PK is silently ignored.
        $this->store->recordDonation($this->baseDonation('ord_dupe', ['amount_cents' => 9999]));
        $this->assertSame(1, $this->countRows('donations'));
        // First insert's amount wins — confirms INSERT OR IGNORE, not REPLACE.
        $this->assertSame(1000, (int) $this->findDonationRow('ord_dupe')['amount_cents']);
    }

    public function test_donationExists_tracks_insertion(): void
    {
        $this->assertFalse($this->store->donationExists('ord_new'));
        $this->store->recordDonation($this->baseDonation('ord_new'));
        $this->assertTrue($this->store->donationExists('ord_new'));
    }

    public function test_markRefunded_flips_status_and_sets_timestamp(): void
    {
        $this->store->recordDonation($this->baseDonation('ord_r', ['payment_intent_id' => 'pi_r']));
        $before = time();
        $this->store->markRefunded('pi_r');
        $row = $this->findDonationRow('ord_r');
        $this->assertSame('refunded', $row['status']);
        $this->assertGreaterThanOrEqual($before, (int) $row['refunded_at']);
    }

    public function test_markRefunded_is_noop_for_unknown_payment_intent(): void
    {
        // No exception, no row change.
        $this->store->markRefunded('pi_never_seen');
        $this->assertSame(0, $this->countRows('donations'));
    }

    public function test_markSubscriptionCancelled_flips_pending_rows_only(): void
    {
        // A paid row and a pending row share the same subscription.
        $this->store->recordDonation($this->baseDonation('ord_paid', [
            'status'                 => 'paid',
            'stripe_subscription_id' => 'sub_1',
            'payment_intent_id'      => 'pi_paid',
        ]));
        $this->store->recordDonation($this->baseDonation('ord_pending', [
            'status'                 => 'pending',
            'stripe_subscription_id' => 'sub_1',
            'payment_intent_id'      => 'pi_pending',
        ]));

        $this->store->markSubscriptionCancelled('sub_1');

        $this->assertSame('paid',      $this->findDonationRow('ord_paid')['status']);
        $this->assertSame('cancelled', $this->findDonationRow('ord_pending')['status']);
    }

    /** @param array<string,mixed> $overrides */
    private function baseDonation(string $orderId, array $overrides = []): array
    {
        return array_replace([
            'order_id'          => $orderId,
            'payment_intent_id' => 'pi_' . $orderId,
            'amount_cents'      => 2500,
            'currency'          => 'usd',
            'email'             => 'd@example.com',
            'contact_name'      => 'D',
            'status'            => 'paid',
            'livemode'          => true,
        ], $overrides);
    }
}
