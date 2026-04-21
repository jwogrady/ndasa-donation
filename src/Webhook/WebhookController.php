<?php
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
     */
    public function dispatch(Event $event): bool
    {
        if (!$this->store->markProcessed($event->id, $event->type)) {
            return true; // duplicate delivery — ack and move on
        }

        try {
            match ($event->type) {
                'checkout.session.completed'     => $this->onCheckoutCompleted($event->data->object),
                'charge.refunded'                => $this->onRefund($event->data->object),
                'payment_intent.payment_failed'  => $this->onPaymentFailed($event->data->object),
                default                          => null,
            };
            return true;
        } catch (\Throwable $e) {
            error_log('Webhook handler error: ' . $e->getMessage());
            return false;
        }
    }

    private function onCheckoutCompleted(object $session): void
    {
        if (($session->payment_status ?? '') !== 'paid') {
            // Async methods may arrive unpaid first; a later event will confirm.
            return;
        }

        $orderId         = (string) ($session->client_reference_id ?? '');
        $paymentIntentId = (string) ($session->payment_intent ?? '');
        $amountCents     = (int)    ($session->amount_total ?? 0);
        $currency        = (string) ($session->currency ?? 'usd');
        $email           = (string) (($session->customer_details->email ?? null)
                                  ?? ($session->customer_email ?? ''));
        $name            = (string) ($session->customer_details->name ?? '');

        if ($orderId === '' || $paymentIntentId === '' || $amountCents <= 0 || $email === '') {
            error_log('Incomplete checkout.session.completed for session ' . ($session->id ?? '?'));
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
        ]);

        // Stripe emails the donor via receipt_email; notify staff ourselves.
        try {
            $this->mailer->sendInternalNotification([
                'order_id'     => $orderId,
                'amount_cents' => $amountCents,
                'currency'     => $currency,
                'email'        => $email,
                'name'         => $name,
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
