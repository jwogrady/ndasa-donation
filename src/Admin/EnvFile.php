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
 * Narrow, safe .env reader/updater for the admin config panel.
 *
 * - Preserves every line in the original file (comments, blanks, unknown keys)
 *   and only rewrites the values for keys that are explicitly updated.
 * - Keys present in $updates but absent from the file are appended at the end.
 * - Quotes values that need it; leaves simple values bare.
 * - Writes to .env.tmp in the same directory and rename()s over .env so a
 *   crash mid-write cannot leave a truncated file that bricks the bootstrap.
 * - Rejects values containing \r or \n to prevent newline injection.
 */
final class EnvFile
{
    public function __construct(private readonly string $path) {}

    /** @return array<string,string> */
    public function read(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }
        $lines = @file($this->path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = self::unquote(trim($v));
        }
        return $out;
    }

    /**
     * Atomically update the .env file. Only keys in $updates are touched;
     * everything else (comments, blank lines, unrelated keys) is preserved.
     *
     * @param array<string,string> $updates
     * @throws \InvalidArgumentException if any value contains CR/LF
     * @throws \RuntimeException         if the file cannot be written atomically
     */
    public function update(array $updates): void
    {
        foreach ($updates as $k => $v) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
                throw new \InvalidArgumentException("Invalid env key: {$k}");
            }
            if (preg_match('/[\r\n]/', $v)) {
                throw new \InvalidArgumentException("Value for {$k} contains a newline");
            }
        }

        $existing = is_file($this->path)
            ? (@file($this->path, FILE_IGNORE_NEW_LINES) ?: [])
            : [];

        $seen = [];
        $out  = [];

        foreach ($existing as $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#' || !str_contains($line, '=')) {
                $out[] = $line;
                continue;
            }
            [$k] = explode('=', $line, 2);
            $k = trim($k);
            if (array_key_exists($k, $updates)) {
                $out[]   = $k . '=' . self::formatValue($updates[$k]);
                $seen[$k] = true;
            } else {
                $out[] = $line;
            }
        }

        // Append any keys that weren't already present.
        foreach ($updates as $k => $v) {
            if (!isset($seen[$k])) {
                $out[] = $k . '=' . self::formatValue($v);
            }
        }

        $content = implode("\n", $out) . "\n";
        $this->atomicWrite($content);
    }

    private function atomicWrite(string $content): void
    {
        $dir = dirname($this->path);
        if (!is_writable($dir) || (is_file($this->path) && !is_writable($this->path))) {
            throw new \RuntimeException(
                'Cannot write to ' . $this->path . ' (check file and directory permissions).'
            );
        }

        $tmp = $this->path . '.tmp';
        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($content)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to write temporary .env.');
        }

        // Match the existing file's mode if we can; otherwise fall back to 600.
        $mode = is_file($this->path) ? (fileperms($this->path) & 0777) : 0o600;
        @chmod($tmp, $mode);

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to atomically replace .env.');
        }
    }

    private static function formatValue(string $v): string
    {
        // Quote when value contains spaces, #, or = so the line round-trips safely.
        if ($v === '' || preg_match('/[\s#=]/', $v)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        }
        return $v;
    }

    private static function unquote(string $v): string
    {
        if (strlen($v) >= 2) {
            $first = $v[0];
            $last  = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($v, 1, -1);
            }
        }
        return $v;
    }
}
