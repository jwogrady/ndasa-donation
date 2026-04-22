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
    public function markProcessed(string $eventId, string $type): bool
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO stripe_events (id, type, received_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$eventId, $type, time()]);
        return $stmt->rowCount() === 1;
    }

    /** @param array{order_id:string,payment_intent_id:?string,amount_cents:int,currency:string,email:string,contact_name:string,status:string,dedication?:string,email_optin?:?bool,interval?:?string,stripe_subscription_id?:?string,stripe_customer_id?:?string} $d */
    public function recordDonation(array $d): void
    {
        $dedication = (string) ($d['dedication'] ?? '');
        $emailOptin = $d['email_optin'] ?? null;
        $interval   = $d['interval'] ?? null;
        $subId      = $d['stripe_subscription_id'] ?? null;
        $custId     = $d['stripe_customer_id'] ?? null;
        $this->db->prepare('INSERT OR IGNORE INTO donations
            (order_id, payment_intent_id, amount_cents, currency, email, contact_name, status, created_at,
             dedication, email_optin, "interval", stripe_subscription_id, stripe_customer_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
     * Mark every donation row linked to a subscription as cancelled. Used
     * when customer.subscription.deleted fires. Does NOT alter historical
     * paid rows' status — 'paid' remains paid — but we record cancellation
     * on rows that were still in a pre-charge state (rare edge case).
     */
    public function markSubscriptionCancelled(string $subscriptionId): void
    {
        // No status change for paid rows; we only annotate at the row level.
        // A future column 'subscription_status' could track this explicitly;
        // today the information lives on Stripe's side and the admin detail
        // view reads the Stripe dashboard link for the authoritative state.
        // This method exists as a safe no-op so the webhook handler has a
        // call site to extend later without re-plumbing the controller.
        $stmt = $this->db->prepare(
            "UPDATE donations SET status = 'cancelled'
             WHERE stripe_subscription_id = ? AND status NOT IN ('paid','refunded')"
        );
        $stmt->execute([$subscriptionId]);
    }

    public function markRefunded(string $paymentIntentId): void
    {
        $this->db->prepare(
            'UPDATE donations SET status = ?, refunded_at = ? WHERE payment_intent_id = ?'
        )->execute(['refunded', time(), $paymentIntentId]);
    }
}
