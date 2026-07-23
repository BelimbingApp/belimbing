<?php

namespace App\Base\Foundation\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Resolves the bootstrap-owned proxy boundary from Laravel config at request
 * time, after the configuration repository is available.
 */
final class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string
     */
    protected function proxies(): array|string
    {
        $configured = trim((string) config('security.trusted_proxies', ''));

        if ($configured === '*') {
            return '*';
        }

        if ($configured !== '') {
            return array_values(array_filter(
                array_map(trim(...), explode(',', $configured)),
                static fn (string $proxy): bool => $proxy !== '',
            ));
        }

        return [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ];
    }
}
