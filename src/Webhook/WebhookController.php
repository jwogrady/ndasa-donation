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

namespace NDASA\Webhook;

use NDASA\Mail\ReceiptMailer;
use Stripe\Event;

final class WebhookController
{
    public function __construct(
        private readonly EventStore $store,
        private readonly ReceiptMailer $mailer,
    ) {}

    /**
     * Dispatch a verified Stripe event. Returns true if handled (idempotently
     * or otherwise); false only for internal failures that Stripe should retry.
     *
     * Idempotency uses a two-phase commit:
     *   1. Check whether this event was already processed (query only, no write).
     *   2. Run the handler.
     *   3. Only if the handler succeeds, record the event as processed.
     *
     * This avoids a real-money data-loss class of bug: if we marked the event
     * processed up front and the handler then failed, Stripe's retry would see
     * a "duplicate" and skip — silently losing the donation. The downstream
     * writes (INSERT OR IGNORE on donations, idempotent UPDATE on refunds)
     * tolerate the retry-before-mark window just fine.
     */
    public function dispatch(Event $event): bool
    {
        if ($this->store->isProcessed($event->id)) {
            return true; // duplicate delivery — ack and move on
        }

        try {
            match ($event->type) {
                'checkout.session.completed'               => $this->onCheckoutCompleted($event->data->object),
                'checkout.session.async_payment_succeeded' => $this->onAsyncPaymentSucceeded($event->data->object),
                'checkout.session.async_payment_failed'    => $this->onAsyncPaymentFailed($event->data->object),
                'charge.refunded'                          => $this->onRefund($event->data->object),
                'payment_intent.payment_failed'            => $this->onPaymentFailed($event->data->object),
                default                                    => null,
            };
        } catch (\Throwable $e) {
            error_log('Webhook handler error: ' . $e->getMessage());
            return false;
        }

        // Handler succeeded — record the event so a redelivery is a no-op.
        // A failure here would cause a redelivery to re-run the handler; the
        // downstream writes are idempotent, so that is acceptable.
        try {
            $this->store->markProcessed($event->id, $event->type);
        } catch (\Throwable $e) {
            error_log('Webhook mark-processed failed (handler already ran): ' . $e->getMessage());
        }
        return true;
    }

    private function onCheckoutCompleted(object $session): void
    {
        if (($session->payment_status ?? '') !== 'paid') {
            // Async methods (ACH, etc.) arrive unpaid first; async_payment_succeeded confirms later.
            return;
        }
        $this->recordPaidSession($session);
    }

    private function onAsyncPaymentSucceeded(object $session): void
    {
        $this->recordPaidSession($session);
    }

    private function onAsyncPaymentFailed(object $session): void
    {
        $orderId = (string) ($session->client_reference_id ?? '');
        $sid     = (string) ($session->id ?? '?');
        error_log("Webhook: async payment failed for session {$sid} (order {$orderId})");
    }

    private function recordPaidSession(object $session): void
    {
        $orderId         = (string) ($session->client_reference_id ?? '');
        $paymentIntentId = (string) ($session->payment_intent ?? '');
        $amountCents     = (int)    ($session->amount_total ?? 0);
        $currency        = (string) ($session->currency ?? 'usd');
        $email           = (string) (($session->customer_details->email ?? null)
                                  ?? ($session->customer_email ?? ''));
        $name            = (string) ($session->customer_details->name ?? '');
        // Checkout sessions don't themselves carry the payment_intent metadata,
        // but they do carry their own metadata object when set. We set
        // dedication on the PaymentIntent, not the session, so retrieve the
        // session with its metadata expanded. Safe to fall back to empty.
        $dedication = (string) ($session->metadata->dedication ?? '');

        if ($orderId === '' || $paymentIntentId === '' || $amountCents <= 0 || $email === '') {
            error_log('Incomplete paid session ' . ($session->id ?? '?'));
            return;
        }

        $this->store->recordDonation([
            'order_id'          => $orderId,
            'payment_intent_id' => $paymentIntentId,
            'amount_cents'      => $amountCents,
            'currency'          => $currency,
            'email'             => $email,
            'contact_name'      => $name,
            'status'            => 'paid',
            'dedication'        => $dedication,
        ]);

        // Stripe emails the donor via receipt_email; notify staff ourselves.
        try {
            $this->mailer->sendInternalNotification([
                'order_id'     => $orderId,
                'amount_cents' => $amountCents,
                'currency'     => $currency,
                'email'        => $email,
                'name'         => $name,
                'dedication'   => $dedication,
            ]);
        } catch (\Throwable $e) {
            // Donation is already recorded — don't retry just because mail is down.
            error_log('Internal notification failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    private function onRefund(object $charge): void
    {
        $pi = (string) ($charge->payment_intent ?? '');
        if ($pi !== '') {
            $this->store->markRefunded($pi);
        }
    }

    private function onPaymentFailed(object $pi): void
    {
        error_log('Webhook: payment failed for PI ' . ($pi->id ?? '?'));
    }
}
