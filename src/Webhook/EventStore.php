<?php
declare(strict_types=1);

namespace NDASA\Webhook;

use PDO;

final class EventStore
{
    public function __construct(private readonly PDO $db) {}

    /** @return bool true if new, false if already processed */
    public function markProcessed(string $eventId, string $type): bool
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO stripe_events (id, type, received_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$eventId, $type, time()]);
        return $stmt->rowCount() === 1;
    }

    /** @param array{order_id:string,payment_intent_id:string,amount_cents:int,currency:string,email:string,contact_name:string,status:string} $d */
    public function recordDonation(array $d): void
    {
        $this->db->prepare('INSERT OR IGNORE INTO donations
            (order_id, payment_intent_id, amount_cents, currency, email, contact_name, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $d['order_id'],
                $d['payment_intent_id'],
                $d['amount_cents'],
                $d['currency'],
                $d['email'],
                $d['contact_name'],
                $d['status'],
                time(),
            ]);
    }

    public function markRefunded(string $paymentIntentId): void
    {
        $this->db->prepare(
            'UPDATE donations SET status = ?, refunded_at = ? WHERE payment_intent_id = ?'
        )->execute(['refunded', time(), $paymentIntentId]);
    }
}
