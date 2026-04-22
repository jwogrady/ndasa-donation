<?php
/**
 * Plugin Name: NDASA Shared Environment
 * Description: Bridges ~/.ndasa-donation/.env into WordPress so WP Mail SMTP
 *              (and any other env-aware plugin) reads the same credentials
 *              as the donation app. Single source of truth for SMTP,
 *              and nothing sensitive in wp-config.php or the wp_options
 *              table.
 * Version:     1.0.0
 * Author:      Status26 Inc for NDASA Foundation
 *
 * Installed as a must-use plugin:
 *     public_html/wp-content/mu-plugins/ndasa-shared-env.php
 *
 * Must-use plugins load before regular plugins and cannot be disabled
 * from the admin, so the SMTP constants defined here are visible to
 * WP Mail SMTP during its own bootstrap.
 */

if (!defined('ABSPATH')) {
    exit;
}

(static function (): void {
    // ABSPATH ends with a trailing slash; dirname() gives us the site root.
    // On Nexcess managed WP this resolves inside the chroot, not /home/<user>.
    $envPath = dirname(ABSPATH) . '/.ndasa-donation/.env';
    if (!is_readable($envPath)) {
        return;
    }

    // Minimal dotenv parser. Intentionally no dependency on vlucas/phpdotenv
    // so WordPress doesn't need to autoload the donation app's Composer tree.
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === '') {
            continue;
        }
        // Strip matched surrounding quotes; leave interior quotes intact.
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }
        if (!isset($_ENV[$k])) {
            $_ENV[$k] = $v;
        }
    }

    // Bridge the shared SMTP_* vars into the constants WP Mail SMTP reads.
    // Defining these before wp-settings.php loads the plugin means the
    // plugin's UI fields become read-only with a "defined in code" notice;
    // the stored option values are ignored in favour of these constants.
    if (!empty($_ENV['SMTP_HOST'])) {
        defined('WPMS_ON')              or define('WPMS_ON',              true);
        defined('WPMS_MAILER')          or define('WPMS_MAILER',          'smtp');
        defined('WPMS_SMTP_HOST')       or define('WPMS_SMTP_HOST',       $_ENV['SMTP_HOST']);
        defined('WPMS_SMTP_PORT')       or define('WPMS_SMTP_PORT',       (int) ($_ENV['SMTP_PORT'] ?? 587));
        defined('WPMS_SSL')             or define('WPMS_SSL',             strtolower((string) ($_ENV['SMTP_ENCRYPTION'] ?? 'tls')));
        defined('WPMS_SMTP_AUTH')       or define('WPMS_SMTP_AUTH',       !empty($_ENV['SMTP_USERNAME']));
        defined('WPMS_SMTP_USER')       or define('WPMS_SMTP_USER',       $_ENV['SMTP_USERNAME'] ?? '');
        defined('WPMS_SMTP_PASS')       or define('WPMS_SMTP_PASS',       $_ENV['SMTP_PASSWORD'] ?? '');
    }
    if (!empty($_ENV['MAIL_FROM'])) {
        defined('WPMS_MAIL_FROM')       or define('WPMS_MAIL_FROM',       $_ENV['MAIL_FROM']);
    }
    if (!empty($_ENV['MAIL_FROM_NAME'])) {
        defined('WPMS_MAIL_FROM_NAME')  or define('WPMS_MAIL_FROM_NAME',  $_ENV['MAIL_FROM_NAME']);
    }
})();
