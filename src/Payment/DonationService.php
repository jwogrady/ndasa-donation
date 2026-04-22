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

namespace NDASA\Payment;

use Stripe\Checkout\Session as StripeSession;

final class DonationService
{
    public const INTERVAL_ONCE  = 'once';
    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR  = 'year';

    private const CURRENCY = 'usd';
    private const PRODUCT_NAME = 'NDASA Foundation Donation';
    private const SESSION_TTL_SECONDS = 1800;

    public function __construct(private readonly string $appUrl) {}

    /**
     * Create a Checkout Session. Branches on $interval:
     *   - 'once'  => one-time payment (mode=payment)
     *   - 'month' => recurring monthly subscription (mode=subscription)
     *   - 'year'  => recurring yearly subscription (mode=subscription)
     *
     * Subscriptions use inline price_data with recurring.interval. The price
     * (including any fee-cover gross-up) is fixed for the lifetime of the
     * subscription — changing it later would require a new Price object.
     */
    public function createCheckoutSession(
        int $amountCents,
        string $email,
        string $contactName,
        string $orderId,
        string $dedication = '',
        bool $emailOptin = false,
        string $interval = self::INTERVAL_ONCE,
    ): StripeSession {
        $interval = in_array($interval, [self::INTERVAL_ONCE, self::INTERVAL_MONTH, self::INTERVAL_YEAR], true)
            ? $interval
            : self::INTERVAL_ONCE;

        // Shared metadata, on both the session and either the PaymentIntent
        // (one-time) or the Subscription (recurring), so webhooks can read
        // them without an extra API round-trip.
        $metadata = [
            'order_id'     => $orderId,
            'contact_name' => $contactName,
            'email_optin'  => $emailOptin ? '1' : '0',
            'interval'     => $interval,
        ];
        if ($dedication !== '') {
            $metadata['dedication'] = $dedication;
        }

        $params = [
            'payment_method_types' => ['card'],
            'customer_email'       => $email,
            'client_reference_id'  => $orderId,
            'metadata'             => $metadata,
            'success_url'          => $this->appUrl . '/success?sid={CHECKOUT_SESSION_ID}',
            'cancel_url'           => $this->appUrl . '/?canceled=1',
            'expires_at'           => time() + self::SESSION_TTL_SECONDS,
            'submit_type'          => 'donate',
        ];

        if ($interval === self::INTERVAL_ONCE) {
            $params['mode'] = 'payment';
            $params['line_items'] = [[
                'price_data' => [
                    'currency'     => self::CURRENCY,
                    'product_data' => ['name' => self::PRODUCT_NAME],
                    'unit_amount'  => $amountCents,
                ],
                'quantity' => 1,
            ]];
            $params['payment_intent_data'] = [
                'description'   => 'NDASA Donation',
                'receipt_email' => $email,
                'metadata'      => $metadata,
            ];
        } else {
            // Subscription mode: the session creates a Customer, a Subscription,
            // and an initial Invoice. The first invoice.paid fires alongside
            // checkout.session.completed; the webhook handler dedupes via
            // stripe_events (event id) and donations.order_id.
            $params['mode'] = 'subscription';
            $params['line_items'] = [[
                'price_data' => [
                    'currency'     => self::CURRENCY,
                    'product_data' => ['name' => self::PRODUCT_NAME],
                    'unit_amount'  => $amountCents,
                    'recurring'    => ['interval' => $interval],
                ],
                'quantity' => 1,
            ]];
            $params['subscription_data'] = [
                'description' => 'NDASA Donation (' . $interval . 'ly recurring)',
                'metadata'    => $metadata,
            ];
        }

        return StripeSession::create(
            $params,
            ['idempotency_key' => 'sess_' . $orderId],
        );
    }

    /**
     * Create a Stripe Customer Portal session so a recurring donor can
     * manage or cancel their subscription. Returned URL is single-use and
     * expires quickly — call this on demand, don't cache.
     */
    public function createPortalSession(string $stripeCustomerId, string $returnPath = '/'): \Stripe\BillingPortal\Session
    {
        return \Stripe\BillingPortal\Session::create([
            'customer'   => $stripeCustomerId,
            'return_url' => $this->appUrl . $returnPath,
        ]);
    }
}
