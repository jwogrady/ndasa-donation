<?php
declare(strict_types=1);

namespace NDASA\Tests\Support;

use Stripe\Event;

/**
 * Helper for constructing Stripe SDK `Event` objects from array payloads
 * that mirror the real webhook shapes our handlers consume.
 *
 * `Event::constructFrom($payload)` walks the nested `data.object` and
 * returns a fully-typed object graph with the `$event->data->object->x`
 * access pattern the controller uses. This gives tests the same API
 * surface Stripe's SDK does, without any network or signature work.
 */
final class Fixtures
{
    /**
     * A `checkout.session.completed` event for a completed one-time donation.
     *
     * @param array<string,mixed> $overrides      Session-level keys to override.
     * @param array<string,mixed> $eventOverrides Top-level event keys to override.
     */
    public static function checkoutSessionCompleted(
        array $overrides = [],
        array $eventOverrides = []
    ): Event {
        $session = array_replace_recursive([
            'id'                  => 'cs_test_' . bin2hex(random_bytes(8)),
            'object'              => 'checkout.session',
            'client_reference_id' => bin2hex(random_bytes(16)),
            'payment_intent'      => 'pi_test_' . bin2hex(random_bytes(8)),
            'subscription'        => null,
            'customer'            => null,
            'amount_total'        => 2500,
            'currency'            => 'usd',
            'customer_email'      => 'donor@example.com',
            'customer_details'    => [
                'email' => 'donor@example.com',
                'name'  => 'Jane Donor',
            ],
            'payment_status'      => 'paid',
            'status'              => 'complete',
            'mode'                => 'payment',
            'livemode'            => false,
            'metadata'            => [
                'interval'    => 'once',
                'email_optin' => '1',
                'dedication'  => '',
            ],
        ], $overrides);

        return self::makeEvent('checkout.session.completed', $session, $eventOverrides);
    }

    public static function checkoutSessionAsyncSucceeded(array $overrides = []): Event
    {
        $overrides['payment_status'] = $overrides['payment_status'] ?? 'paid';
        $event = self::checkoutSessionCompleted($overrides);
        $event->type = 'checkout.session.async_payment_succeeded';
        return $event;
    }

    public static function checkoutSessionAsyncFailed(array $overrides = []): Event
    {
        $overrides['payment_status'] = 'unpaid';
        $event = self::checkoutSessionCompleted($overrides);
        $event->type = 'checkout.session.async_payment_failed';
        return $event;
    }

    public static function chargeRefunded(string $paymentIntentId, array $overrides = []): Event
    {
        $charge = array_replace_recursive([
            'id'             => 'ch_test_' . bin2hex(random_bytes(8)),
            'object'         => 'charge',
            'payment_intent' => $paymentIntentId,
            'refunded'       => true,
            'amount'         => 2500,
            'currency'       => 'usd',
        ], $overrides);

        return self::makeEvent('charge.refunded', $charge);
    }

    public static function invoicePaid(array $overrides = []): Event
    {
        $invoice = array_replace_recursive([
            'id'             => 'in_test_' . bin2hex(random_bytes(8)),
            'object'         => 'invoice',
            'subscription'   => 'sub_test_' . bin2hex(random_bytes(8)),
            'customer'       => 'cus_test_' . bin2hex(random_bytes(8)),
            'customer_email' => 'recurring@example.com',
            'customer_name'  => 'Recurring Donor',
            'payment_intent' => 'pi_test_' . bin2hex(random_bytes(8)),
            'amount_paid'    => 10000,
            'currency'       => 'usd',
            'livemode'       => false,
            'status'         => 'paid',
            'lines'          => [
                'data' => [[
                    'price' => ['recurring' => ['interval' => 'month']],
                ]],
            ],
        ], $overrides);

        return self::makeEvent('invoice.paid', $invoice);
    }

    public static function invoicePaymentFailed(array $overrides = []): Event
    {
        $event = self::invoicePaid($overrides);
        $event->type = 'invoice.payment_failed';
        return $event;
    }

    public static function subscriptionDeleted(string $subscriptionId, array $overrides = []): Event
    {
        $sub = array_replace_recursive([
            'id'     => $subscriptionId,
            'object' => 'subscription',
            'status' => 'canceled',
        ], $overrides);

        return self::makeEvent('customer.subscription.deleted', $sub);
    }

    public static function paymentIntentPaymentFailed(array $overrides = []): Event
    {
        $pi = array_replace_recursive([
            'id'                 => 'pi_test_' . bin2hex(random_bytes(8)),
            'object'             => 'payment_intent',
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ], $overrides);

        return self::makeEvent('payment_intent.payment_failed', $pi);
    }

    /**
     * @param array<string,mixed> $objectPayload
     * @param array<string,mixed> $eventOverrides
     */
    private static function makeEvent(string $type, array $objectPayload, array $eventOverrides = []): Event
    {
        $eventPayload = array_replace_recursive([
            'id'       => 'evt_test_' . bin2hex(random_bytes(8)),
            'object'   => 'event',
            'type'     => $type,
            'livemode' => (bool) ($objectPayload['livemode'] ?? false),
            'data'     => ['object' => $objectPayload],
        ], $eventOverrides);

        return Event::constructFrom($eventPayload);
    }
}
