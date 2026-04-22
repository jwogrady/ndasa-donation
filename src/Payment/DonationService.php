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
    private const CURRENCY = 'usd';
    private const PRODUCT_NAME = 'NDASA Foundation Donation';
    private const SESSION_TTL_SECONDS = 1800;

    public function __construct(private readonly string $appUrl) {}

    public function createCheckoutSession(
        int $amountCents,
        string $email,
        string $contactName,
        string $orderId,
    ): StripeSession {
        return StripeSession::create(
            [
                'mode'                      => 'payment',
                'automatic_payment_methods' => ['enabled' => true],
                'customer_email'            => $email,
                'client_reference_id'       => $orderId,
                'line_items' => [[
                    'price_data' => [
                        'currency'     => self::CURRENCY,
                        'product_data' => ['name' => self::PRODUCT_NAME],
                        'unit_amount'  => $amountCents,
                    ],
                    'quantity' => 1,
                ]],
                'payment_intent_data' => [
                    'description'   => 'NDASA Donation',
                    'receipt_email' => $email,
                    'metadata'      => [
                        'order_id'     => $orderId,
                        'contact_name' => $contactName,
                    ],
                ],
                'success_url' => $this->appUrl . '/success?sid={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $this->appUrl . '/?canceled=1',
                'expires_at'  => time() + self::SESSION_TTL_SECONDS,
                'submit_type' => 'donate',
            ],
            ['idempotency_key' => 'sess_' . $orderId],
        );
    }
}
