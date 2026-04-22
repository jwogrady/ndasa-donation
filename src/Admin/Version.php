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

namespace NDASA\Admin;

/**
 * Resolve an app-version string for the admin UI.
 *
 * Preference order:
 *   1. Short git hash from .git/HEAD (seven characters), if the repo is
 *      present and readable. No shell-outs; we parse the files directly.
 *   2. The static FALLBACK constant below.
 *
 * Any failure along the way returns the fallback; this function must
 * never throw.
 */
final class Version
{
    /** Static fallback when git metadata is not available (tarball / production copy). */
    public const FALLBACK = '1.0.0';

    public static function current(): string
    {
        try {
            $hash = self::gitShortHash();
            if ($hash !== null) {
                return $hash;
            }
        } catch (\Throwable) {
            // Fall through.
        }
        return self::FALLBACK;
    }

    private static function gitShortHash(): ?string
    {
        $root = dirname(__DIR__, 2);
        $git  = $root . '/.git';
        if (!is_dir($git)) {
            return null;
        }

        $head = @file_get_contents($git . '/HEAD');
        if (!is_string($head)) {
            return null;
        }
        $head = trim($head);

        // Detached HEAD stores the commit SHA directly.
        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            return substr($head, 0, 7);
        }

        // Normal case: HEAD points to a ref (e.g. "ref: refs/heads/master").
        if (!preg_match('#^ref:\s*(refs/[\w/.-]+)$#', $head, $m)) {
            return null;
        }
        $refPath = $git . '/' . $m[1];
        if (is_file($refPath)) {
            $sha = trim((string) @file_get_contents($refPath));
            if (preg_match('/^[0-9a-f]{40}$/', $sha)) {
                return substr($sha, 0, 7);
            }
        }

        // Packed refs fallback for repositories whose loose refs have been
        // garbage-collected.
        $packed = $git . '/packed-refs';
        if (is_file($packed)) {
            $lines = @file($packed, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                    continue;
                }
                [$sha, $ref] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');
                if ($ref === $m[1] && preg_match('/^[0-9a-f]{40}$/', $sha)) {
                    return substr($sha, 0, 7);
                }
            }
        }

        return null;
    }
}
