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

    /** @param array{order_id:string,payment_intent_id:string,amount_cents:int,currency:string,email:string,contact_name:string,status:string,dedication?:string,email_optin?:?bool} $d */
    public function recordDonation(array $d): void
    {
        $dedication = (string) ($d['dedication'] ?? '');
        $emailOptin = $d['email_optin'] ?? null;
        $this->db->prepare('INSERT OR IGNORE INTO donations
            (order_id, payment_intent_id, amount_cents, currency, email, contact_name, status, created_at, dedication, email_optin)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
            ]);
    }

    public function markRefunded(string $paymentIntentId): void
    {
        $this->db->prepare(
            'UPDATE donations SET status = ?, refunded_at = ? WHERE payment_intent_id = ?'
        )->execute(['refunded', time(), $paymentIntentId]);
    }
}
