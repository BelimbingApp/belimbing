<?php
namespace App\Modules\Core\AI\Services\Browser;

use App\Base\AI\Services\UrlSafetyGuard;

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
    private readonly UrlSafetyGuard $urlSafetyGuard;

    public function __construct(
        ?UrlSafetyGuard $urlSafetyGuard = null,
    ) {
        $this->urlSafetyGuard = $urlSafetyGuard ?? new UrlSafetyGuard;
    }

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
        return $this->urlSafetyGuard->validate(
            url: $url,
            allowPrivateNetwork: (bool) config('ai.tools.browser.ssrf_policy.allow_private_network', false),
            hostnameAllowlist: (array) config('ai.tools.browser.ssrf_policy.hostname_allowlist', []),
        );
    }
}
