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

use NDASA\Support\Database;
use NDASA\Webhook\EventStore;
use NDASA\Webhook\WebhookController;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

// Stripe always sends application/json. Short-circuit anything else so
// scanners and probes don't burn cycles through signature verification.
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if ($contentType !== '' && !str_starts_with(strtolower($contentType), 'application/json')) {
    http_response_code(415);
    exit;
}

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === false || $payload === '' || $sig === '') {
    http_response_code(400);
    exit;
}

// Verify against BOTH mode secrets when present. Stripe retries events
// signed by the previous mode for up to 3 days; flipping the admin toggle
// while a retry is in flight must not cause that retry to be abandoned.
// First-secret-that-validates wins; malformed-payload errors (raised by the
// Stripe SDK before any signature check) short-circuit out of the loop.
$candidates = array_values(array_filter([
    $_ENV['STRIPE_LIVE_WEBHOOK_SECRET'] ?? $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null,
    $_ENV['STRIPE_TEST_WEBHOOK_SECRET'] ?? null,
], static fn ($v) => is_string($v) && $v !== ''));

if ($candidates === []) {
    error_log('Webhook: no signing secrets configured');
    http_response_code(500);
    exit;
}

$event = null;
$lastSigError = null;
foreach ($candidates as $secret) {
    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret, tolerance: 300);
        break;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        $lastSigError = $e;
        continue;
    } catch (\UnexpectedValueException $e) {
        // Malformed payload / wrong API version — not a per-secret failure.
        // v20+ also throws here when a V2 event notification is posted to a
        // V1 endpoint. Either way, retrying with another secret is pointless.
        error_log('Webhook: invalid/malformed payload — ' . $e->getMessage());
        http_response_code(400);
        exit;
    }
}

if ($event === null) {
    error_log('Webhook: signature failed against all configured secrets — '
        . ($lastSigError ? $lastSigError->getMessage() : 'no error'));
    http_response_code(400);
    exit;
}

$controller = new WebhookController(
    new EventStore(Database::connection()),
);

if (!$controller->dispatch($event)) {
    // Non-2xx triggers Stripe retry — safer than swallowing a transient failure.
    http_response_code(500);
    exit;
}

http_response_code(200);
