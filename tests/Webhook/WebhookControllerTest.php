<?php
declare(strict_types=1);

namespace NDASA\Tests\Webhook;

use NDASA\Mail\ReceiptMailer;
use NDASA\Tests\Support\DatabaseTestCase;
use NDASA\Tests\Support\Fixtures;
use NDASA\Webhook\EventStore;
use NDASA\Webhook\WebhookController;

/**
 * Behavior tests for the webhook event dispatcher.
 *
 * These tests stand the webhook controller up with:
 *   - a real `EventStore` against an in-memory SQLite DB (same schema as prod),
 *   - a real `ReceiptMailer` on Symfony Mailer's `null://null` transport so
 *     send() silently drops mail with no network,
 *   - real Stripe SDK event objects built from array fixtures.
 *
 * No HTTP, no Stripe API calls for outbound events, no signature verification
 * (that's tested separately in the webhook-entry-point integration). The
 * goal is to verify the controller's row-writing and idempotency behaviors
 * in isolation, end-to-end through the ledger.
 */
final class WebhookControllerTest extends DatabaseTestCase
{
    private EventStore $store;
    private WebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['MAIL_FROM']          = 'test@example.com';
        $_ENV['MAIL_BCC_INTERNAL']  = 'staff@example.com';
        $this->store      = new EventStore($this->db);
        $this->controller = new WebhookController($this->store, new ReceiptMailer('null://null'));
    }

    protected function tearDown(): void
    {
        unset($_ENV['MAIL_FROM'], $_ENV['MAIL_BCC_INTERNAL']);
    }

    // ───────────── checkout.session.completed ─────────────

    public function test_completed_session_writes_donation_row(): void
    {
        $event = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_one_time',
            'payment_intent'      => 'pi_one',
            'amount_total'        => 2606,
            'customer_details'    => ['email' => 'j@example.com', 'name' => 'John'],
        ]);
        $this->assertTrue($this->controller->dispatch($event));

        $row = $this->findDonationRow('ord_one_time');
        $this->assertNotNull($row);
        $this->assertSame(2606,         (int) $row['amount_cents']);
        $this->assertSame('pi_one',     $row['payment_intent_id']);
        $this->assertSame('paid',       $row['status']);
        $this->assertSame('j@example.com', $row['email']);
        $this->assertSame('John',       $row['contact_name']);
    }

    public function test_completed_session_tags_livemode_from_event(): void
    {
        $live = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_live',
            'livemode'            => true,
        ]);
        $test = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_test',
            'livemode'            => false,
        ]);
        $this->controller->dispatch($live);
        $this->controller->dispatch($test);

        $this->assertSame(1, (int) $this->findDonationRow('ord_live')['livemode']);
        $this->assertSame(0, (int) $this->findDonationRow('ord_test')['livemode']);
    }

    public function test_completed_session_preserves_dedication_and_optin(): void
    {
        $event = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_ded',
            'metadata'            => [
                'interval'    => 'once',
                'email_optin' => '0',
                'dedication'  => 'In honor of Ada',
            ],
        ]);
        $this->controller->dispatch($event);

        $row = $this->findDonationRow('ord_ded');
        $this->assertSame('In honor of Ada', $row['dedication']);
        $this->assertSame(0, (int) $row['email_optin']);
    }

    public function test_completed_session_ignores_unpaid_status(): void
    {
        $event = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_unpaid',
            'payment_status'      => 'unpaid',  // async ACH hasn't cleared yet
        ]);
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertNull($this->findDonationRow('ord_unpaid'));
    }

    public function test_completed_session_requires_client_reference_id(): void
    {
        // Foreign sessions (WPForms, etc.) omit client_reference_id.
        // Handler logs and returns; never writes a broken row.
        $event = Fixtures::checkoutSessionCompleted(['client_reference_id' => '']);
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertSame(0, $this->countRows('donations'));
    }

    public function test_completed_session_requires_payment_intent_for_one_time(): void
    {
        $event = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_no_pi',
            'payment_intent'      => '',  // one-time session missing its PI
        ]);
        $this->controller->dispatch($event);
        $this->assertSame(0, $this->countRows('donations'));
    }

    public function test_completed_session_subscription_mode_allows_missing_payment_intent(): void
    {
        // Subscription sessions carry a subscription id but no PI on the
        // session itself — the PI lives on the invoice.
        $event = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_sub',
            'payment_intent'      => '',
            'subscription'        => 'sub_xyz',
            'customer'            => 'cus_xyz',
        ]);
        $this->assertTrue($this->controller->dispatch($event));

        $row = $this->findDonationRow('ord_sub');
        $this->assertNotNull($row);
        $this->assertNull($row['payment_intent_id']);
        $this->assertSame('sub_xyz', $row['stripe_subscription_id']);
        $this->assertSame('cus_xyz', $row['stripe_customer_id']);
    }

    public function test_duplicate_event_id_is_idempotent(): void
    {
        $event = Fixtures::checkoutSessionCompleted(['client_reference_id' => 'ord_dupe_evt']);
        $this->controller->dispatch($event);
        // Second delivery of the same event: controller sees it in stripe_events
        // and acks without calling the handler again.
        $this->controller->dispatch($event);

        $this->assertSame(1, $this->countRows('donations'));
        $this->assertSame(1, $this->countRows('stripe_events'));
    }

    public function test_stripe_event_id_is_recorded_after_successful_handle(): void
    {
        $event = Fixtures::checkoutSessionCompleted(['client_reference_id' => 'ord_tracked']);
        $this->controller->dispatch($event);

        $this->assertTrue($this->store->isProcessed($event->id));
    }

    // ───────────── charge.refunded ─────────────

    public function test_refund_flips_existing_donation_status(): void
    {
        $completed = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_r',
            'payment_intent'      => 'pi_r',
        ]);
        $this->controller->dispatch($completed);

        $refund = Fixtures::chargeRefunded('pi_r');
        $this->assertTrue($this->controller->dispatch($refund));

        $row = $this->findDonationRow('ord_r');
        $this->assertSame('refunded', $row['status']);
        $this->assertNotNull($row['refunded_at']);
    }

    public function test_refund_for_unknown_payment_intent_is_noop(): void
    {
        $event = Fixtures::chargeRefunded('pi_never_seen_here');
        $this->assertTrue($this->controller->dispatch($event));
        // No donations row, no crash; just the stripe_events ack row.
        $this->assertSame(0, $this->countRows('donations'));
        $this->assertSame(1, $this->countRows('stripe_events'));
    }

    // ───────────── async payment methods ─────────────

    public function test_async_payment_succeeded_records_row(): void
    {
        $event = Fixtures::checkoutSessionAsyncSucceeded([
            'client_reference_id' => 'ord_ach',
            'payment_intent'      => 'pi_ach',
            'amount_total'        => 5000,
        ]);
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertNotNull($this->findDonationRow('ord_ach'));
    }

    public function test_async_payment_failed_logs_but_no_row(): void
    {
        $event = Fixtures::checkoutSessionAsyncFailed([
            'client_reference_id' => 'ord_fail',
            'payment_intent'      => 'pi_fail',
        ]);
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertSame(0, $this->countRows('donations'));
    }

    // ───────────── payment_intent.payment_failed ─────────────

    public function test_payment_intent_payment_failed_is_log_only(): void
    {
        $event = Fixtures::paymentIntentPaymentFailed();
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertSame(0, $this->countRows('donations'));
    }

    // ───────────── invoice.paid ─────────────

    public function test_invoice_paid_records_recurring_row_with_synthetic_order_id(): void
    {
        $event = Fixtures::invoicePaid([
            'id'             => 'in_renewal1',
            'subscription'   => 'sub_abc',
            'customer_email' => 'recurring@example.com',
            'amount_paid'    => 10000,
        ]);
        $this->assertTrue($this->controller->dispatch($event));

        // order_id is minted from the invoice id: "inv_<id>"
        $row = $this->findDonationRow('inv_in_renewal1');
        $this->assertNotNull($row);
        $this->assertSame(10000,                 (int) $row['amount_cents']);
        $this->assertSame('recurring@example.com', $row['email']);
        $this->assertSame('sub_abc',             $row['stripe_subscription_id']);
        $this->assertSame('month',               $row['interval']);
        $this->assertSame('paid',                $row['status']);
    }

    public function test_invoice_paid_for_yearly_plan_records_yearly_interval(): void
    {
        $event = Fixtures::invoicePaid([
            'id'           => 'in_yearly',
            'subscription' => 'sub_yearly',
            'lines'        => ['data' => [['price' => ['recurring' => ['interval' => 'year']]]]],
        ]);
        $this->controller->dispatch($event);
        $this->assertSame('year', $this->findDonationRow('inv_in_yearly')['interval']);
    }

    public function test_invoice_paid_without_subscription_is_noop(): void
    {
        // One-off invoices sent from the Stripe dashboard arrive with
        // subscription=null; they aren't donations.
        $event = Fixtures::invoicePaid(['id' => 'in_one_off', 'subscription' => '']);
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertSame(0, $this->countRows('donations'));
    }

    public function test_invoice_paid_without_email_is_noop(): void
    {
        $event = Fixtures::invoicePaid(['id' => 'in_no_email', 'customer_email' => '']);
        $this->controller->dispatch($event);
        $this->assertSame(0, $this->countRows('donations'));
    }

    public function test_invoice_paid_is_idempotent_on_order_id(): void
    {
        // Same invoice redelivered twice — the second dispatch sees the event
        // in stripe_events first and acks without re-running. Even if it did
        // re-run, INSERT OR IGNORE on the synthetic order_id protects the
        // row count. Test both layers by dispatching twice with distinct
        // event ids but the same invoice id.
        $invoiceFields = ['id' => 'in_idem', 'subscription' => 'sub_idem'];
        $first  = Fixtures::invoicePaid($invoiceFields);
        $second = Fixtures::invoicePaid($invoiceFields);
        // Ensure different event ids so stripe_events can't short-circuit the
        // second dispatch.
        $this->assertNotSame($first->id, $second->id);

        $this->controller->dispatch($first);
        $this->controller->dispatch($second);
        $this->assertSame(1, $this->countRows('donations'));
    }

    // ───────────── customer.subscription.deleted ─────────────

    public function test_subscription_deleted_leaves_paid_rows_alone(): void
    {
        $completed = Fixtures::checkoutSessionCompleted([
            'client_reference_id' => 'ord_paid_sub',
            'subscription'        => 'sub_cancelme',
            'payment_intent'      => '',  // subscription session has no PI
        ]);
        $this->controller->dispatch($completed);

        $cancel = Fixtures::subscriptionDeleted('sub_cancelme');
        $this->assertTrue($this->controller->dispatch($cancel));

        // Historical paid revenue must never silently flip on cancel.
        $this->assertSame('paid', $this->findDonationRow('ord_paid_sub')['status']);
    }

    // ───────────── invoice.payment_failed ─────────────

    public function test_invoice_payment_failed_is_log_only(): void
    {
        $event = Fixtures::invoicePaymentFailed();
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertSame(0, $this->countRows('donations'));
    }

    // ───────────── unknown event types ─────────────

    public function test_unknown_event_type_is_ignored_successfully(): void
    {
        $event = \Stripe\Event::constructFrom([
            'id'       => 'evt_weird',
            'object'   => 'event',
            'type'     => 'some.new.event.we.dont.handle',
            'livemode' => false,
            'data'     => ['object' => ['id' => 'x']],
        ]);
        // Handler match has a `default => null` branch — the event is
        // acked (200) so Stripe doesn't retry forever.
        $this->assertTrue($this->controller->dispatch($event));
        $this->assertTrue($this->store->isProcessed('evt_weird'));
    }

    // ───────────── handler error path ─────────────

    public function test_dispatch_returns_false_when_handler_throws(): void
    {
        // Drop the donations table to force the INSERT inside
        // recordDonation() to throw a PDOException. The controller's
        // outer try/catch logs and returns false; stripe_events must
        // NOT be updated so Stripe's retry will try again instead of
        // silently deduping a failed event.
        $this->db->exec('DROP TABLE donations');

        $event = Fixtures::checkoutSessionCompleted(['client_reference_id' => 'ord_x']);
        $this->assertFalse($this->controller->dispatch($event));
        $this->assertFalse($this->store->isProcessed($event->id));
    }
}
