<?php

namespace App\Base\AI\Services;

/**
 * Stateless SSRF guard for validating outbound URLs.
 *
 * Applies a consistent URL safety policy for AI web and browser features:
 * - Allows only http/https schemes
 * - Blocks loopback aliases and .local domains
 * - Optionally allowlists hostnames
 * - Blocks private/reserved IP targets unless explicitly allowed
 */
class UrlSafetyGuard
{
    /**
     * @param  (\Closure(string): list<string>)|null  $resolver
     */
    public function __construct(
        private readonly ?\Closure $resolver = null,
    ) {}

    /**
     * Validate whether a URL is safe to fetch.
     *
     * @param  string  $url  URL to validate
     * @param  bool  $allowPrivateNetwork  Whether private/reserved targets are allowed
     * @param  list<string>  $hostnameAllowlist  fnmatch patterns to bypass IP checks
     * @return string|true True when safe, otherwise an error message
     */
    public function validate(
        string $url,
        bool $allowPrivateNetwork = false,
        array $hostnameAllowlist = [],
    ): string|true {
        $parsed = parse_url($url);

        $structureError = $this->checkUrlStructure($parsed);
        if ($structureError !== null) {
            return $structureError;
        }

        /** @var array{host: string} $parsed */
        $host = strtolower($parsed['host']);

        // Blocked hostnames (localhost, 0.0.0.0, ::1, .local) are only
        // blocked when private networks are not explicitly allowed — local
        // LLM providers (e.g., Ollama on localhost) need these targets.
        if (! $allowPrivateNetwork) {
            $blockedHostnameError = $this->checkBlockedHostname($host);
            if ($blockedHostnameError !== null) {
                return $blockedHostnameError;
            }
        }

        // Always run IP range checks, even when allowPrivateNetwork is true.
        // The allowPrivateNetwork flag narrows the policy to permit loopback
        // and private ranges but still blocks link-local (cloud metadata),
        // multicast, and other dangerous reserved ranges.
        $ipRangeError = null;

        if (! $this->matchesAllowlist($host, $hostnameAllowlist)) {
            $ipRangeError = $this->checkIpRange($host, $allowPrivateNetwork);
        }

        return $ipRangeError ?? true;
    }

    /**
     * Resolve a hostname to a single validated public IP to pin the outbound
     * connection to, closing the DNS-rebinding window between validation and
     * fetch: {@see validate()} resolves DNS to decide safety, but a second
     * resolution at connect time could return a different (internal) address.
     * The caller connects to this exact IP so no re-resolution can occur.
     *
     * Returns null when pinning is unnecessary or must be skipped: an IP-literal
     * host (already concrete), an allowlisted host, or when private networks are
     * explicitly permitted. Returns null too when resolution yields no public
     * address — {@see validate()} is responsible for turning that into a block.
     *
     * @param  list<string>  $hostnameAllowlist
     */
    public function pinnedIpFor(
        string $host,
        bool $allowPrivateNetwork = false,
        array $hostnameAllowlist = [],
    ): ?string {
        $host = strtolower($host);
        $pinnedIp = null;

        if ($this->pinningRequiredFor($host, $allowPrivateNetwork, $hostnameAllowlist)) {
            foreach ($this->resolveHostIps($host) as $ip) {
                if ($this->validateResolvedIp($ip, $host, $allowPrivateNetwork) === null) {
                    $pinnedIp = $ip;
                    break;
                }
            }
        }

        return $pinnedIp;
    }

    /**
     * @param  list<string>  $hostnameAllowlist
     */
    public function pinningRequiredFor(
        string $host,
        bool $allowPrivateNetwork = false,
        array $hostnameAllowlist = [],
    ): bool {
        $host = strtolower($host);

        return ! $allowPrivateNetwork
            && ! $this->matchesAllowlist($host, $hostnameAllowlist)
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    /**
     * @param  array<string, mixed>|false  $parsed
     */
    private function checkUrlStructure(array|false $parsed): ?string
    {
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return 'Invalid URL: unable to parse.';
        }

        $scheme = strtolower((string) $parsed['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'Only http and https URLs are allowed.';
        }

        return strtolower((string) $parsed['host']) === '' ? 'Invalid URL: empty hostname.' : null;
    }

    private function checkBlockedHostname(string $host): ?string
    {
        if ($host === 'localhost' || $host === '0.0.0.0' || $host === '::1') {
            return "Blocked: requests to {$host} are not allowed.";
        }

        return str_ends_with($host, '.local')
            ? 'Blocked: requests to .local domains are not allowed.'
            : null;
    }

    private function checkIpRange(string $host, bool $allowPrivateNetwork = false): ?string
    {
        $ipRangeError = null;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ipRangeError = $this->validateResolvedIp($host, $host, $allowPrivateNetwork);
        } else {
            $ips = $this->resolveHostIps($host);

            if ($ips === []) {
                $ipRangeError = "Blocked: unable to resolve hostname {$host}.";
            } else {
                foreach ($ips as $ip) {
                    $ipRangeError = $this->validateResolvedIp($ip, $host, $allowPrivateNetwork);

                    if ($ipRangeError !== null) {
                        break;
                    }
                }
            }
        }

        return $ipRangeError;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || $records === []) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Validate a resolved IP against the safety policy.
     *
     * When allowPrivateNetwork is false: blocks both private and reserved
     * ranges (default SSRF protection).
     *
     * When allowPrivateNetwork is true: permits loopback (127.0.0.0/8, ::1)
     * and private ranges (10.x, 172.16-31.x, 192.168.x, fc00::/7) for local
     * LLM providers, but always blocks link-local (169.254.0.0/16 — includes
     * cloud metadata endpoints), multicast, and other dangerous reserved
     * ranges.
     */
    private function validateResolvedIp(string $ip, string $host, bool $allowPrivateNetwork = false): ?string
    {
        // PHP's FILTER_FLAG_NO_RES_RANGE misses IPv4 multicast (224.0.0.0/4)
        // and IPv6 multicast (ff00::/8). Block these explicitly — they are
        // always dangerous regardless of the allowPrivateNetwork flag.
        if ($this->isMulticast($ip)) {
            return $this->ipBlockedError($ip, $host);
        }

        // Public IP — always allowed.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return null;
        }

        if ($allowPrivateNetwork) {
            // Loopback (127.0.0.0/8, ::1) — allowed for local providers.
            if (str_starts_with($ip, '127.') || $ip === '::1') {
                return null;
            }

            // Private ranges (10.x, 172.16-31.x, 192.168.x, fc00::/7) —
            // allowed but not reserved. We confirm the IP is private
            // (blocked by NO_PRIV_RANGE) but NOT reserved (passes
            // NO_RES_RANGE), excluding it from link-local/metadata ranges.
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false
            ) {
                return null;
            }
        }

        // Everything else — blocked (includes link-local, cloud metadata,
        // 0.0.0.0/8, 240.0.0.0/4, etc.).
        return $this->ipBlockedError($ip, $host);
    }

    /**
     * Check if an IP is in a multicast range (IPv4 224.0.0.0/4 or
     * IPv6 ff00::/8). PHP's FILTER_FLAG_NO_RES_RANGE does not cover these.
     */
    private function isMulticast(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // 224.0.0.0/4: first octet 224–239.
            $firstOctet = (int) explode('.', $ip)[0];

            return $firstOctet >= 224 && $firstOctet <= 239;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ff00::/8: first hex group starts with "ff".
            return str_starts_with(strtolower($ip), 'ff');
        }

        return false;
    }

    private function ipBlockedError(string $ip, string $host): string
    {
        if ($ip === $host) {
            return "Blocked: {$host} is a private or reserved IP address.";
        }

        return "Blocked: {$host} resolves to a private or reserved IP address ({$ip}).";
    }

    /**
     * @param  list<string>  $hostnameAllowlist
     */
    private function matchesAllowlist(string $host, array $hostnameAllowlist): bool
    {
        foreach ($hostnameAllowlist as $pattern) {
            if (fnmatch(strtolower($pattern), $host)) {
                return true;
            }
        }

        return false;
    }
}
