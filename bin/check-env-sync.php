#!/usr/bin/env php
<?php
/**
 * Verify that .env.example (dev starter) and deploy/.env.template (prod
 * starter) define the same set of env keys. Defaults legitimately differ
 * — only the key *names* must stay in sync, so that operators of either
 * file get the same admin-config surface.
 *
 * Exit 0 on success. Exit 1 on drift, printing which keys are missing
 * where.
 *
 * Usage: php bin/check-env-sync.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dev  = $root . '/.env.example';
$prod = $root . '/deploy/.env.template';

$parse = static function (string $path): array {
    if (!is_readable($path)) {
        fwrite(STDERR, "check-env-sync: cannot read {$path}\n");
        exit(1);
    }
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $lineNo => $line) {
        $trim = ltrim($line);
        // Count keys even when commented (e.g. "# SMTP_DSN=..."), so an
        // optional-but-documented key in one file is reflected in the other.
        if (preg_match('/^#?\s*([A-Z][A-Z0-9_]*)\s*=/', $trim, $m)) {
            $keys[$m[1]] = $lineNo + 1;
        }
    }
    return $keys;
};

$devKeys  = $parse($dev);
$prodKeys = $parse($prod);

$onlyDev  = array_diff_key($devKeys,  $prodKeys);
$onlyProd = array_diff_key($prodKeys, $devKeys);

if (!$onlyDev && !$onlyProd) {
    $count = count($devKeys);
    fwrite(STDOUT, "env-sync: OK — {$count} keys match between .env.example and deploy/.env.template\n");
    exit(0);
}

fwrite(STDERR, "env-sync: DRIFT detected between .env.example and deploy/.env.template\n\n");
if ($onlyDev) {
    fwrite(STDERR, "  Only in .env.example:\n");
    foreach ($onlyDev as $k => $lineNo) {
        fwrite(STDERR, "    - {$k}  (line {$lineNo})\n");
    }
    fwrite(STDERR, "\n");
}
if ($onlyProd) {
    fwrite(STDERR, "  Only in deploy/.env.template:\n");
    foreach ($onlyProd as $k => $lineNo) {
        fwrite(STDERR, "    - {$k}  (line {$lineNo})\n");
    }
    fwrite(STDERR, "\n");
}
fwrite(STDERR, "Add the missing key(s) to whichever file is short, or remove from the other.\n");
exit(1);
