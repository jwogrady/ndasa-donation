<?php
declare(strict_types=1);

namespace NDASA\Webhook;

use PDO;

final class EventStore
{
    public function __construct(private readonly PDO $db) {}

    /** Check whether this event has already been marked processed. */
    public function isProcessed(string $eventId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM stripe_events WHERE id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return bool true if newly inserted, false if already present */
    public function markProcessed(string $eventId, string $type, bool $livemode = true): bool
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO stripe_events (id, type, received_at, livemode) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$eventId, $type, time(), $livemode ? 1 : 0]);
        return $stmt->rowCount() === 1;
    }

    /** @param array{order_id:string,payment_intent_id:?string,amount_cents:int,currency:string,email:string,contact_name:string,status:string,dedication?:string,email_optin?:?bool,interval?:?string,stripe_subscription_id?:?string,stripe_customer_id?:?string,livemode?:bool} $d */
    public function recordDonation(array $d): void
    {
        $dedication = (string) ($d['dedication'] ?? '');
        $emailOptin = $d['email_optin'] ?? null;
        $interval   = $d['interval'] ?? null;
        $subId      = $d['stripe_subscription_id'] ?? null;
        $custId     = $d['stripe_customer_id'] ?? null;
        // Default to live so tests and fixtures that omit this flag don't
        // silently land in the test bucket and disappear from the dashboard.
        $livemode   = ($d['livemode'] ?? true) ? 1 : 0;
        $this->db->prepare('INSERT OR IGNORE INTO donations
            (order_id, payment_intent_id, amount_cents, currency, email, contact_name, status, created_at,
             dedication, email_optin, "interval", stripe_subscription_id, stripe_customer_id, livemode)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $d['order_id'],
                $d['payment_intent_id'],
                $d['amount_cents'],
                $d['currency'],
                $d['email'],
                $d['contact_name'],
                $d['status'],
                time(),
                $dedication !== '' ? $dedication : null,
                $emailOptin === null ? null : ($emailOptin ? 1 : 0),
                $interval !== null && $interval !== '' && $interval !== 'once' ? $interval : null,
                $subId   !== null && $subId   !== '' ? $subId   : null,
                $custId  !== null && $custId  !== '' ? $custId  : null,
                $livemode,
            ]);
    }

    /**
     * Check whether a donation row already exists for a given order_id. Used
     * to dedupe the first invoice.paid against the checkout.session.completed
     * that fires alongside it for subscriptions.
     */
    public function donationExists(string $orderId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM donations WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Mark a subscription as cancelled. Fired by customer.subscription.deleted.
     *
     * Two things happen, and they're different on purpose:
     *   1. Every row for the subscription gets subscription_status='cancelled'.
     *      This is the lifecycle flag the dashboard's Active Recurring view
     *      reads to drop the subscription from the monthly commitment total.
     *   2. Pre-charge pending rows flip status='cancelled' (rare edge case).
     *      Paid and refunded rows keep their own status — historical revenue
     *      must not be rewritten just because the donor later cancels.
     */
    public function markSubscriptionCancelled(string $subscriptionId): void
    {
        $this->db->prepare(
            "UPDATE donations SET subscription_status = 'cancelled'
             WHERE stripe_subscription_id = ?"
        )->execute([$subscriptionId]);

        $this->db->prepare(
            "UPDATE donations SET status = 'cancelled'
             WHERE stripe_subscription_id = ? AND status NOT IN ('paid','refunded')"
        )->execute([$subscriptionId]);
    }

    public function markRefunded(string $paymentIntentId): void
    {
        $this->db->prepare(
            'UPDATE donations SET status = ?, refunded_at = ? WHERE payment_intent_id = ?'
        )->execute(['refunded', time(), $paymentIntentId]);
    }

    /**
     * Refund every paid row linked to a subscription. Used by onRefund() when
     * the refunded charge is attached to an invoice for a subscription —
     * because in that case the donations row's payment_intent_id is often
     * null (the signup row was created from checkout.session.completed, which
     * in subscription mode carries no PI), and the PI-keyed markRefunded()
     * lookup silently no-ops.
     */
    public function markSubscriptionRefunded(string $subscriptionId): void
    {
        $this->db->prepare(
            'UPDATE donations SET status = ?, refunded_at = ?
             WHERE stripe_subscription_id = ? AND status = ?'
        )->execute(['refunded', time(), $subscriptionId, 'paid']);
    }

    /**
     * Set payment_intent_id on an existing donation row. Called by onInvoicePaid()
     * to backfill the PI onto a subscription signup row that was created from
     * checkout.session.completed (which in subscription mode has no PI), so
     * later charge.refunded events against that invoice's charge can find
     * the row via its PI like they do for one-time donations.
     */
    public function setPaymentIntentId(string $orderId, string $paymentIntentId): void
    {
        $this->db->prepare(
            'UPDATE donations SET payment_intent_id = ?
             WHERE order_id = ? AND payment_intent_id IS NULL'
        )->execute([$paymentIntentId, $orderId]);
    }
}
