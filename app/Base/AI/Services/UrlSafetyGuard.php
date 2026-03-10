<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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

        $blockedHostnameError = $this->checkBlockedHostname($host);
        if ($blockedHostnameError !== null) {
            return $blockedHostnameError;
        }

        $ipRangeError = null;

        if (! $this->matchesAllowlist($host, $hostnameAllowlist) && ! $allowPrivateNetwork) {
            $ipRangeError = $this->checkIpRange($host);
        }

        return $ipRangeError ?? true;
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

    private function checkIpRange(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->validateResolvedIp($host, $host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || $records === []) {
            return "Blocked: unable to resolve hostname {$host}.";
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

        if ($ips === []) {
            return "Blocked: unable to resolve hostname {$host}.";
        }

        foreach ($ips as $ip) {
            $ipRangeError = $this->validateResolvedIp($ip, $host);

            if ($ipRangeError !== null) {
                return $ipRangeError;
            }
        }

        return null;
    }

    private function validateResolvedIp(string $ip, string $host): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return null;
        }

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
