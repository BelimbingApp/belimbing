<?php

namespace Tests\Support;

use App\Base\AI\Services\UrlSafetyGuard;

/**
 * Approves every URL so tests can keep using unresolvable `.test` hostnames
 * alongside `Http::fake()`.
 *
 * Outbound callers validate before requesting, and validation resolves DNS, so
 * a fake host is blocked before the HTTP fake ever sees it. Bind this in tests
 * that exercise what happens *after* a URL is accepted; the guard's own rules
 * are covered by UrlSafetyGuardTest.
 */
final class PermissiveUrlSafetyGuard extends UrlSafetyGuard
{
    public function validate(
        string $url,
        bool $allowPrivateNetwork = false,
        array $hostnameAllowlist = [],
    ): string|true {
        return true;
    }
}
