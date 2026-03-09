<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

/**
 * Reusable SSRF protection guard for browser-related URL validation.
 *
 * Shared by both BrowserTool and WebFetchTool to enforce consistent
 * URL safety checks. Blocks requests to private/internal networks,
 * loopback addresses, link-local ranges, and reserved IP ranges.
 *
 * Policy is controlled via config('ai.tools.browser.ssrf_policy'):
 * - allow_private_network: bypass IP range checks (development only)
 * - hostname_allowlist: fnmatch patterns that bypass IP checks
 */
class BrowserSsrfGuard
{
    /**
     * Validate whether the URL is safe from SSRF.
     *
     * Checks are applied in order: URL structure, scheme, hostname
     * blocklist, allowlist bypass, and finally resolved-IP range
     * validation (unless allow_private_network is enabled).
     *
     * @param  string  $url  The URL to validate
     * @return string|true True if safe, error string if blocked
     */
    public function validate(string $url): string|true
    {
        $parsed = parse_url($url);

        $structureError = $this->checkUrlStructure($parsed);
        if ($structureError !== null) {
            return $structureError;
        }

        /** @var array{scheme: string, host: string} $parsed */
        $host = strtolower($parsed['host']);

        $policyError = $this->checkHostPolicy($host);

        return $policyError ?? true;
    }

    /**
     * Validate the structural components of a parsed URL.
     *
     * Checks that the URL is parseable, uses http/https, and has a non-empty hostname.
     *
     * @param  array<string, mixed>|false  $parsed  Result of parse_url()
     * @return string|null Error message, or null if valid
     */
    private function checkUrlStructure(array|false $parsed): ?string
    {
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return 'Invalid URL: unable to parse.';
        }

        $scheme = strtolower($parsed['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'Only http and https URLs are allowed.';
        }

        return strtolower($parsed['host']) === '' ? 'Invalid URL: empty hostname.' : null;
    }

    /**
     * Check the hostname and IP policy for SSRF risks.
     *
     * Applies blocklist checks, allowlist bypass, private-network bypass,
     * and finally IP range validation.
     *
     * @param  string  $host  Lowercase hostname extracted from the URL
     * @return string|null Error message if blocked, null if allowed
     */
    private function checkHostPolicy(string $host): ?string
    {
        $blockReason = $this->checkBlockedHostname($host);
        if ($blockReason !== null) {
            return $blockReason;
        }

        $allowlist = (array) config('ai.tools.browser.ssrf_policy.hostname_allowlist', []);

        if ($this->matchesAllowlist($host, $allowlist)
            || config('ai.tools.browser.ssrf_policy.allow_private_network', false)) {
            return null;
        }

        return $this->checkIpRange($host);
    }

    /**
     * Check whether the hostname is on the explicit blocklist.
     *
     * Blocks loopback aliases (localhost, 0.0.0.0, ::1) and .local domains.
     *
     * @param  string  $host  Lowercase hostname to check
     * @return string|null Error message if blocked, null if not on blocklist
     */
    private function checkBlockedHostname(string $host): ?string
    {
        if ($host === 'localhost' || $host === '0.0.0.0' || $host === '::1') {
            return "Blocked: requests to {$host} are not allowed.";
        }

        return str_ends_with($host, '.local')
            ? 'Blocked: requests to .local domains are not allowed.'
            : null;
    }

    /**
     * Resolve and validate the IP address range for the given hostname.
     *
     * Blocks unresolvable hostnames and IPs in private or reserved ranges.
     *
     * @param  string  $host  Lowercase hostname to resolve and check
     * @return string|null Error message if blocked, null if IP is acceptable
     */
    private function checkIpRange(string $host): ?string
    {
        $error = null;

        // If the host is already a literal IP address, validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $error = "Blocked: {$host} is a private or reserved IP address.";
            }
        } else {
            // Resolve all A and AAAA records for the hostname.
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);

            if ($records === false || $records === []) {
                $error = "Blocked: unable to resolve hostname {$host}.";
            } else {
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
                    $error = "Blocked: unable to resolve hostname {$host}.";
                } else {
                    // Block if any resolved IP is in a private or reserved range.
                    foreach ($ips as $ip) {
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                            $error = "Blocked: {$host} resolves to a private or reserved IP address ({$ip}).";
                            break;
                        }
                    }
                }
            }
        }

        return $error;
    }

    /**
     * Check whether the hostname matches any pattern in the allowlist.
     *
     * Supports fnmatch-style wildcards (e.g., '*.example.com').
     *
     * @param  string  $host  Lowercase hostname to check
     * @param  array<int, string>  $allowlist  Patterns to match against
     */
    private function matchesAllowlist(string $host, array $allowlist): bool
    {
        foreach ($allowlist as $pattern) {
            if (fnmatch(strtolower($pattern), $host)) {
                return true;
            }
        }

        return false;
    }
}
