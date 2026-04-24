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
                'invoice.paid'                             => $this->onInvoicePaid($event->data->object),
                'invoice.payment_failed'                   => $this->onInvoicePaymentFailed($event->data->object),
                'customer.subscription.deleted'            => $this->onSubscriptionDeleted($event->data->object),
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
            $this->store->markProcessed($event->id, $event->type, (bool) ($event->livemode ?? false));
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
        $subscriptionId  = (string) ($session->subscription ?? '');
        $customerId      = (string) ($session->customer ?? '');
        $amountCents     = (int)    ($session->amount_total ?? 0);
        $currency        = (string) ($session->currency ?? 'usd');
        // Stripe sets `livemode` on the session object itself — default true
        // so pre-column-change test fixtures don't silently get tagged as
        // test data.
        $livemode        = (bool)   ($session->livemode ?? true);
        $email           = (string) (($session->customer_details->email ?? null)
                                  ?? ($session->customer_email ?? ''));
        $name            = (string) ($session->customer_details->name ?? '');
        // DonationService writes dedication into the Checkout session's
        // metadata object at create-time; Stripe delivers it back on the
        // completed event. Absent = no dedication.
        $dedication = (string) ($session->metadata->dedication ?? '');
        // email_optin is stored as '1' / '0' string in Stripe metadata to
        // survive the JSON round-trip; absent = pre-optin-feature = unknown.
        $emailOptinRaw = $session->metadata->email_optin ?? null;
        $emailOptin = $emailOptinRaw === null ? null : ($emailOptinRaw === '1');
        // interval: 'once' (or absent) = NULL in DB; 'month'/'year' = recurring.
        $intervalRaw = (string) ($session->metadata->interval ?? 'once');
        $interval = in_array($intervalRaw, ['month', 'year'], true) ? $intervalRaw : null;

        // Subscription sessions don't carry a payment_intent on the session
        // itself — the PI lives on the invoice. One-time sessions must have
        // one or we refuse to record the row.
        $isSubscription = $subscriptionId !== '';
        if ($orderId === '' || $amountCents <= 0 || $email === ''
            || (!$isSubscription && $paymentIntentId === '')) {
            error_log('Incomplete paid session ' . ($session->id ?? '?'));
            return;
        }

        $this->store->recordDonation([
            'order_id'               => $orderId,
            'payment_intent_id'      => $paymentIntentId !== '' ? $paymentIntentId : null,
            'amount_cents'           => $amountCents,
            'currency'               => $currency,
            'email'                  => $email,
            'contact_name'           => $name,
            'status'                 => 'paid',
            'interval'               => $interval,
            'stripe_subscription_id' => $subscriptionId !== '' ? $subscriptionId : null,
            'stripe_customer_id'     => $customerId !== '' ? $customerId : null,
            'dedication'             => $dedication,
            'email_optin'            => $emailOptin,
            'livemode'               => $livemode,
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

    /**
     * invoice.paid fires for both the first charge of a new subscription
     * (alongside checkout.session.completed) AND every subsequent recurring
     * charge. For the first one, the session handler already recorded a row
     * keyed by order_id — we skip here if that row exists. For every
     * recurring charge after the first, we mint a synthetic order_id from
     * the invoice id so the PK stays unique.
     */
    private function onInvoicePaid(object $invoice): void
    {
        $subscriptionId = (string) ($invoice->subscription ?? '');
        if ($subscriptionId === '') {
            // Not a subscription invoice (one-off invoices from the dashboard,
            // etc.) — out of scope for this app.
            return;
        }

        $invoiceId  = (string) ($invoice->id ?? '');
        $amountPaid = (int)    ($invoice->amount_paid ?? 0);
        $currency   = (string) ($invoice->currency ?? 'usd');
        $customerId = (string) ($invoice->customer ?? '');
        $email      = (string) ($invoice->customer_email ?? '');
        $name       = (string) ($invoice->customer_name ?? '');
        $piId       = (string) ($invoice->payment_intent ?? '');
        $livemode   = (bool)   ($invoice->livemode ?? true);

        if ($invoiceId === '' || $amountPaid <= 0 || $email === '') {
            error_log('Webhook: invoice.paid missing required fields (invoice ' . $invoiceId . ')');
            return;
        }

        // First invoice of a new subscription: the session handler owns the
        // row. If it exists, no-op — we're the redundant sibling event.
        //
        // Stripe puts metadata.donation_first_invoice_order_id on the
        // subscription (set at creation via subscription_data.metadata), so
        // we retrieve the subscription to find the signup row's order_id.
        $signupOrderId = null;
        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $signupOrderId = (string) ($subscription->metadata->order_id ?? '') ?: null;
        } catch (\Throwable $e) {
            error_log('Webhook: could not retrieve subscription ' . $subscriptionId . ': ' . $e->getMessage());
            // Fall through — we can still record a row keyed by invoice id.
        }

        if ($signupOrderId !== null && $this->store->donationExists($signupOrderId)) {
            // This is the first invoice and the session handler already
            // recorded the row. Check: did it get recorded without a PI
            // (subscription mode omits it on the session)? Backfill the PI
            // on the existing row would require a new setter on EventStore;
            // skipping for now — the Stripe dashboard is authoritative for
            // PI lookup and we link out via subscription_id from the admin.
            return;
        }

        // Subsequent recurring charge. Use the invoice id as a deterministic,
        // unique order_id so retries stay idempotent via the PK.
        $orderId = 'inv_' . $invoiceId;

        $this->store->recordDonation([
            'order_id'               => $orderId,
            'payment_intent_id'      => $piId !== '' ? $piId : null,
            'amount_cents'           => $amountPaid,
            'currency'               => $currency,
            'email'                  => $email,
            'contact_name'           => $name,
            'status'                 => 'paid',
            'interval'               => $this->intervalFromInvoice($invoice),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_customer_id'     => $customerId !== '' ? $customerId : null,
            'dedication'             => '',
            'email_optin'            => null,
            'livemode'               => $livemode,
        ]);
    }

    /**
     * invoice.payment_failed — card declined, expired, etc. Stripe's
     * retry/dunning flow handles recovery; we just log so the operator has
     * visibility in app.log.
     */
    private function onInvoicePaymentFailed(object $invoice): void
    {
        $subId = (string) ($invoice->subscription ?? '?');
        $iid   = (string) ($invoice->id ?? '?');
        error_log("Webhook: invoice.payment_failed sub={$subId} inv={$iid}");
    }

    /**
     * Donor cancelled (or Stripe cancelled after exhausting retries). Past
     * paid invoices stay paid; we only mark any non-paid pending rows as
     * cancelled. Historical revenue is untouched.
     */
    private function onSubscriptionDeleted(object $subscription): void
    {
        $id = (string) ($subscription->id ?? '');
        if ($id === '') {
            return;
        }
        $this->store->markSubscriptionCancelled($id);
        error_log("Webhook: subscription {$id} cancelled");
    }

    /** Extract 'month' | 'year' | null from an invoice's first line. */
    private function intervalFromInvoice(object $invoice): ?string
    {
        $lines = $invoice->lines->data ?? [];
        foreach ($lines as $line) {
            $interval = (string) ($line->price->recurring->interval ?? '');
            if ($interval === 'month' || $interval === 'year') {
                return $interval;
            }
        }
        return null;
    }
}
