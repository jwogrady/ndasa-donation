<?php
/**
 * NDASA Donation Platform — public shim.
 *
 * The real front controller lives at ../.ndasa-donation/public/index.php,
 * which is above the URL-reachable path but still inside the PHP-FPM chroot.
 * This file is the only donation-app entry point the web tier loads directly.
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

require __DIR__ . '/../.ndasa-donation/public/index.php';
