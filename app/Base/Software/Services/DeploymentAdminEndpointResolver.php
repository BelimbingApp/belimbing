<?php

namespace App\Base\Software\Services;

final class DeploymentAdminEndpointResolver
{
    private const LOCAL_ADMIN_HOST = '127.0.0.1';

    private const DEFAULT_ADMIN_PORT = '2019';

    private const WILDCARD_HOSTS = ['0.0.0.0', '::', '[::]'];

    /**
     * Candidate host+port pairs for the FrankenPHP/Caddy admin API.
     *
     * octane:start records it in its server-state file, so we read it from there
     * rather than guessing. Explicit environment config still wins; otherwise probe the
     * detected state before Caddy's stock 2019. Do not guess Windows launcher
     * ports: they may belong to another instance or WSL relay.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function candidates(): array
    {
        $host = config('app.caddy_server_admin_host');
        $port = config('app.caddy_server_admin_port');
        [$stateHost, $statePort] = $this->octaneAdminEndpoint($this->octaneState());

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
     * Candidate application health endpoints after a worker restart.
     *
     * Prefer the locally recorded Octane listener so restart verification does
     * not depend on whether APP_URL's public hostname resolves back to this node.
     * Keep APP_URL as a fallback for direct TLS deployments and older state files.
     *
     * @return list<string>
     */
    public function healthCheckUrls(): array
    {
        $state = $this->octaneState();
        $stateEndpoint = $this->octaneApplicationEndpoint($state);
        $urls = [];

        if ($stateEndpoint !== null) {
            [$host, $port] = $stateEndpoint;
            $urls[] = "http://{$host}:{$port}/up";
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            $urls[] = "{$appUrl}/up";
        }

        return array_values(array_unique($urls));
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
            [self::LOCAL_ADMIN_HOST, self::DEFAULT_ADMIN_PORT],
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function octaneAdminEndpoint(?array $state): array
    {
        if (! is_array($state)) {
            return [null, null];
        }

        $admin = is_array($state['state'] ?? null) ? $state['state'] : $state;

        return [
            is_string($admin['adminHost'] ?? null) ? $admin['adminHost'] : null,
            isset($admin['adminPort']) ? (string) $admin['adminPort'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{0: string, 1: string}|null
     */
    private function octaneApplicationEndpoint(?array $state): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        $app = is_array($state['state'] ?? null) ? $state['state'] : $state;
        $host = is_string($app['host'] ?? null) ? $app['host'] : null;
        $port = isset($app['port']) ? (string) $app['port'] : null;

        if ($port === null || $port === '') {
            return null;
        }

        if ($host === null || $host === '' || in_array($host, self::WILDCARD_HOSTS, true)) {
            $host = self::LOCAL_ADMIN_HOST;
        }

        return [$host, $port];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function octaneState(): ?array
    {
        $statePath = storage_path('logs/octane-server-state.json');
        $state = is_file($statePath)
            ? json_decode((string) file_get_contents($statePath), true)
            : null;

        return is_array($state) ? $state : null;
    }
}
