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

define('NDASA_SKIP_SESSION', true);
require_once __DIR__ . '/../config/app.php';

use NDASA\Mail\ReceiptMailer;
use NDASA\Support\Database;
use NDASA\Webhook\EventStore;
use NDASA\Webhook\WebhookController;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === false || $payload === '' || $sig === '') {
    http_response_code(400);
    exit;
}

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig,
        $_ENV['STRIPE_WEBHOOK_SECRET'],
        tolerance: 300,
    );
} catch (\UnexpectedValueException $e) {
    // v20+ also throws here when a V2 event notification is posted to a V1
    // endpoint. Either way, the payload is not something we can process.
    error_log('Webhook: invalid/malformed payload — ' . $e->getMessage());
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log('Webhook: signature failed — ' . $e->getMessage());
    http_response_code(400);
    exit;
}

$controller = new WebhookController(
    new EventStore(Database::connection()),
    new ReceiptMailer(),
);

if (!$controller->dispatch($event)) {
    // Non-2xx triggers Stripe retry — safer than swallowing a transient failure.
    http_response_code(500);
    exit;
}

http_response_code(200);
