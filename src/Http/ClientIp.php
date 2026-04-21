<?php
declare(strict_types=1);

namespace NDASA\Http;

final class ClientIp
{
    /**
     * Resolve the real client IP, honouring X-Forwarded-For only for hops
     * whose immediate source is in TRUSTED_PROXIES (comma-separated CIDRs
     * or plain IPs). Never trust XFF blindly — it's forgeable otherwise.
     */
    public static function resolve(array $server, string $trustedCsv): string
    {
        $remote = $server['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = array_filter(array_map('trim', explode(',', $trustedCsv)));

        if (!$trusted || !self::ipMatches($remote, $trusted)) {
            return $remote;
        }

        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff === '') {
            return $remote;
        }

        // XFF format: "client, proxy1, proxy2". Walk right-to-left and stop
        // at the first hop that isn't itself a trusted proxy.
        $hops = array_map('trim', explode(',', $xff));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = $hops[$i];
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!self::ipMatches($ip, $trusted)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** @param list<string> $trusted */
    private static function ipMatches(string $ip, array $trusted): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($trusted as $rule) {
            if (str_contains($rule, '/')) {
                if (self::cidrMatch($ip, $rule)) {
                    return true;
                }
            } elseif ($ip === $rule) {
                return true;
            }
        }
        return false;
    }

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bitsStr] = explode('/', $cidr, 2);
        $bits = (int) $bitsStr;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = chr(0xFF << (8 - $remainder) & 0xFF);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
