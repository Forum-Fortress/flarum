<?php

namespace ForumFortress\Flarum;

use InvalidArgumentException;

final class ClientIp
{
    public const DEFAULT_HEADER = 'X-Forwarded-For';

    private const SERVER_KEYS = [
        'X-Forwarded-For' => 'HTTP_X_FORWARDED_FOR',
        'Forwarded' => 'HTTP_FORWARDED',
        'X-Real-IP' => 'HTTP_X_REAL_IP',
        'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
        'True-Client-IP' => 'HTTP_TRUE_CLIENT_IP',
    ];

    public static function supportsHeader(string $header): bool
    {
        return isset(self::SERVER_KEYS[$header]);
    }

    /** @return array{address: string, header: string}|null */
    public static function suggestProxy(array $server, string $preferredHeader): ?array
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        foreach (array_unique([$preferredHeader, ...array_keys(self::SERVER_KEYS)]) as $header) {
            if (self::resolve($server, $remote, $header) !== $remote) {
                return ['address' => $remote, 'header' => $header];
            }
        }

        return null;
    }

    public static function resolve(array $server, string $trustedProxies, string $header): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $headers = [$header];
        if ($trustedProxies === '') {
            if ($header !== self::DEFAULT_HEADER || ! self::isLocalOrReserved($remote)) {
                return $remote;
            }
            $trustedProxies = $remote;
            $headers = array_keys(self::SERVER_KEYS);
        }

        try {
            $ranges = self::parseTrustedProxies($trustedProxies);
        } catch (InvalidArgumentException) {
            return $remote;
        }
        if (! self::isTrusted($remote, $ranges)) {
            return $remote;
        }

        foreach ($headers as $candidate) {
            $client = self::resolveHeader($server, $candidate, $ranges);
            if ($client !== null) {
                return $client;
            }
        }

        return $remote;
    }

    /** @param list<array{string, int}> $ranges */
    private static function resolveHeader(array $server, string $header, array $ranges): ?string
    {
        $serverKey = self::SERVER_KEYS[$header] ?? null;
        $value = $serverKey === null ? null : ($server[$serverKey] ?? null);
        if (! is_string($value) || $value === '' || strlen($value) > 8192) {
            return null;
        }

        $chain = match ($header) {
            'Forwarded' => self::forwardedNodes($value),
            'X-Forwarded-For' => explode(',', $value),
            default => [trim($value, " \t")],
        };

        // A single-address header must be set by the proxy itself. List headers
        // give the order of the connection hops, oldest first.
        $client = null;
        foreach ($chain as $node) {
            $ip = match ($header) {
                'Forwarded', 'X-Forwarded-For' => self::nodeIp($node),
                default => filter_var($node, FILTER_VALIDATE_IP) ?: null,
            };
            if ($ip === null) {
                return null;
            }
            if (! self::isTrusted($ip, $ranges)) {
                $client = $ip;
            }
        }

        return $client;
    }

    /** @return list<array{string, int}> */
    public static function parseTrustedProxies(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $ranges = [];
        foreach (explode(',', $value) as $entry) {
            [$address, $prefix] = array_pad(explode('/', trim($entry), 2), 2, null);
            $network = self::packIp($address);
            if ($network === null) {
                throw new InvalidArgumentException('Enter comma-separated proxy IP addresses or CIDR ranges.');
            }
            $bits = strlen($network) * 8;
            if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix < 1 || (int) $prefix > $bits)) {
                throw new InvalidArgumentException('Enter valid CIDR prefixes. Use ranges that contain only trusted proxies.');
            }

            $ranges[] = [$network, $prefix === null ? $bits : (int) $prefix];
        }

        return $ranges;
    }

    /** @param list<array{string, int}> $ranges */
    private static function isTrusted(string $ip, array $ranges): bool
    {
        $packed = self::packIp($ip);
        if ($packed === null) {
            return false;
        }

        foreach ($ranges as [$network, $bits]) {
            $bytes = intdiv($bits, 8);
            $remaining = $bits % 8;
            if (strlen($packed) !== strlen($network) || substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            if ($remaining === 0 || (ord($packed[$bytes] ^ $network[$bytes]) >> (8 - $remaining)) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function packIp(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : inet_pton($ip);
    }

    private static function isLocalOrReserved(string $ip): bool
    {
        $packed = self::packIp($ip);
        if ($packed === null) {
            return false;
        }

        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }

        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // PHP 8.0 and 8.1 lack the global-range filter.
        static $extraRanges = null;
        $extraRanges ??= self::parseTrustedProxies('100.64.0.0/10, 192.0.2.0/24, 198.18.0.0/15, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32');
        return self::isTrusted($ip, $extraRanges);
    }

    private static function nodeIp(string $node): ?string
    {
        $node = trim($node, " \t");
        if (filter_var($node, FILTER_VALIDATE_IP) !== false) {
            return $node;
        }

        // Ports are allowed with IPv4 or bracketed IPv6. Bare IPv6 is handled
        // above so an address ending in digits is never mistaken for a port.
        if (! preg_match('/\A(?:\[([^\]]+)\](?::([0-9]{1,5}))?|([^:]+):([0-9]{1,5}))\z/', $node, $matches, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        [, $ipv6, $ipv6Port, $ipv4, $ipv4Port] = $matches;
        $address = $ipv6 ?? $ipv4;
        if ((int) ($ipv6Port ?? $ipv4Port) > 65535) {
            return null;
        }

        return filter_var($address, FILTER_VALIDATE_IP, $ipv6 !== null ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)
            ? $address : null;
    }

    /** @return list<string> An empty list signals a malformed header. */
    private static function forwardedNodes(string $value): array
    {
        // Skip complete quoted strings when splitting Forwarded parameters.
        // Backslash escapes are rejected to avoid interpreting ambiguous boundaries.
        if (str_contains($value, '\\') || substr_count($value, '"') % 2 !== 0 || preg_match('/[^\x09\x20-\x7e]/', $value)) {
            return [];
        }

        $nodes = [];
        foreach (preg_split('/"[^"]*"(*SKIP)(*F)|,/', $value) as $element) {
            $node = null;
            foreach (preg_split('/"[^"]*"(*SKIP)(*F)|;/', $element) as $parameter) {
                if (! preg_match('/\A([a-z0-9_-]+)=(?:"([^"]*)"|([^"\s]+))\z/i', trim($parameter, " \t"), $match)) {
                    return [];
                }
                if (strcasecmp($match[1], 'for') !== 0) {
                    continue;
                }
                if ($node !== null) {
                    return [];
                }
                $node = $match[3] ?? $match[2];
            }
            if ($node === null) {
                return [];
            }
            $nodes[] = $node;
        }

        return $nodes;
    }
}
