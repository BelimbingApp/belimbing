<?php

namespace App\Base\Update\Services;

final class DeploymentAdminEndpointResolver
{
    private const LOCAL_ADMIN_HOST = '127.0.0.1';

    private const DEFAULT_ADMIN_PORT = '2019';

    private const WINDOWS_LAUNCHER_ADMIN_PORT = '2020';

    /**
     * Candidate host+port pairs for the FrankenPHP/Caddy admin API.
     *
     * octane:start records it in its server-state file, so we read it from there
     * rather than guessing. Explicit env vars still win; otherwise probe the
     * Windows launcher's default 2020 before Caddy's stock 2019.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function candidates(): array
    {
        $host = getenv('CADDY_SERVER_ADMIN_HOST') ?: null;
        $port = getenv('CADDY_SERVER_ADMIN_PORT') ?: null;
        [$stateHost, $statePort] = $this->octaneAdminEndpoint();

        if ($host !== null || $port !== null) {
            return [$this->configuredAdminEndpoint($host, $port, $stateHost, $statePort)];
        }

        $candidates = array_merge(
            $this->detectedAdminEndpoints($stateHost, $statePort),
            $this->defaultAdminEndpoints(),
        );
        $unique = [];

        foreach ($candidates as $candidate) {
            $unique[implode(':', $candidate)] = $candidate;
        }

        return array_values($unique);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function configuredAdminEndpoint(?string $host, ?string $port, ?string $stateHost, ?string $statePort): array
    {
        return [
            $host ?: ($stateHost ?: self::LOCAL_ADMIN_HOST),
            $port ?: ($statePort ?: self::DEFAULT_ADMIN_PORT),
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function detectedAdminEndpoints(?string $stateHost, ?string $statePort): array
    {
        if ($stateHost === null && $statePort === null) {
            return [];
        }

        return [[$stateHost ?: self::LOCAL_ADMIN_HOST, $statePort ?: self::DEFAULT_ADMIN_PORT]];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function defaultAdminEndpoints(): array
    {
        return [
            [self::LOCAL_ADMIN_HOST, self::WINDOWS_LAUNCHER_ADMIN_PORT],
            [self::LOCAL_ADMIN_HOST, self::DEFAULT_ADMIN_PORT],
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function octaneAdminEndpoint(): array
    {
        $statePath = storage_path('logs/octane-server-state.json');
        $state = is_file($statePath)
            ? json_decode((string) file_get_contents($statePath), true)
            : null;

        if (! is_array($state)) {
            return [null, null];
        }

        $admin = is_array($state['state'] ?? null) ? $state['state'] : $state;

        return [
            is_string($admin['adminHost'] ?? null) ? $admin['adminHost'] : null,
            isset($admin['adminPort']) ? (string) $admin['adminPort'] : null,
        ];
    }
}
