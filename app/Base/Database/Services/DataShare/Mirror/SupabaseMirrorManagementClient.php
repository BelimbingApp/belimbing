<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Exceptions\SupabaseMirrorSetupException;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SupabaseMirrorManagementClient
{
    private const BASE_URL = 'https://api.supabase.com/v1';

    /**
     * @return array{organizations: list<array{id: string, slug: string, name: string}>, projects: list<array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string}>}
     */
    public function discover(string $accessToken): array
    {
        $organizations = $this->request('GET', '/organizations', $accessToken)->json();
        $projects = $this->request('GET', '/projects', $accessToken)->json();

        if (! is_array($organizations) || ! is_array($projects)) {
            throw SupabaseMirrorSetupException::invalidResponse();
        }

        $normalizedOrganizations = array_values(array_filter(array_map(
            fn (mixed $organization): ?array => $this->organization($organization),
            $organizations,
        )));
        $normalizedProjects = array_values(array_filter(array_map(
            fn (mixed $project): ?array => $this->projectData($project),
            $projects,
        )));

        usort($normalizedOrganizations, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        usort($normalizedProjects, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return [
            'organizations' => $normalizedOrganizations,
            'projects' => $normalizedProjects,
        ];
    }

    /** @return array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string} */
    public function project(string $accessToken, string $projectRef): array
    {
        $payload = $this->request('GET', '/projects/'.rawurlencode($projectRef), $accessToken)->json();
        $project = $this->projectData($payload);

        if ($project === null || ! hash_equals($projectRef, $project['ref'])) {
            throw SupabaseMirrorSetupException::invalidResponse();
        }

        return $project;
    }

    /** @return array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string} */
    public function createProject(
        string $accessToken,
        string $organizationSlug,
        string $name,
        string $regionGroup,
        string $databasePassword,
    ): array {
        $payload = $this->request('POST', '/projects', $accessToken, [
            'name' => $name,
            'organization_slug' => $organizationSlug,
            'db_pass' => $databasePassword,
            'region_selection' => [
                'type' => 'smartGroup',
                'code' => $regionGroup,
            ],
        ])->json();
        $project = $this->projectData($payload);

        if ($project === null) {
            throw SupabaseMirrorSetupException::invalidResponse();
        }

        return $project;
    }

    /**
     * Return provider-authored session-pooler coordinates when available, then
     * the stable direct endpoint. The database password is inserted locally and
     * is never sent to the Management API after project creation.
     *
     * @param  array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string}  $project
     * @return non-empty-list<string>
     */
    public function connectionUrls(string $accessToken, array $project, string $databasePassword): array
    {
        $urls = [];
        $pooler = $this->sessionPoolerUrl($accessToken, $project['ref'], $databasePassword);

        if ($pooler !== null) {
            $urls[] = $pooler;
        }

        $host = $this->databaseHost($project);
        $urls[] = $this->postgresUrl('postgres', $databasePassword, $host, 5432);

        return array_values(array_unique($urls));
    }

    private function request(string $method, string $path, string $accessToken, ?array $body = null): Response
    {
        try {
            $request = $this->pendingRequest($accessToken);
            $response = $body === null
                ? $request->send($method, self::BASE_URL.$path)
                : $request->send($method, self::BASE_URL.$path, ['json' => $body]);
        } catch (ConnectionException $exception) {
            throw SupabaseMirrorSetupException::unavailable($exception);
        }

        if ($response->successful()) {
            return $response;
        }

        throw match ($response->status()) {
            401 => SupabaseMirrorSetupException::invalidToken(),
            402 => SupabaseMirrorSetupException::billingRequired(),
            403, 404 => SupabaseMirrorSetupException::forbidden(),
            429 => SupabaseMirrorSetupException::rateLimited(),
            default => SupabaseMirrorSetupException::apiError($response->status()),
        };
    }

    private function pendingRequest(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken(trim($accessToken))
            ->timeout(20)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()]);
    }

    /** @return array{id: string, slug: string, name: string}|null */
    private function organization(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $id = trim((string) ($value['id'] ?? ''));
        $slug = trim((string) ($value['slug'] ?? $id));
        $name = trim((string) ($value['name'] ?? $slug));

        if ($slug === '' || $name === '') {
            return null;
        }

        return ['id' => $id, 'slug' => $slug, 'name' => $name];
    }

    /** @return array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string}|null */
    private function projectData(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $ref = trim((string) ($value['ref'] ?? ''));
        $name = trim((string) ($value['name'] ?? ''));

        if ($ref === '' || $name === '' || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $ref) !== 1) {
            return null;
        }

        return [
            'ref' => $ref,
            'name' => $name,
            'organization_slug' => trim((string) ($value['organization_slug'] ?? $value['organization_id'] ?? '')),
            'region' => trim((string) ($value['region'] ?? '')),
            'status' => trim((string) ($value['status'] ?? '')),
            'database_host' => trim((string) data_get($value, 'database.host', '')),
        ];
    }

    private function sessionPoolerUrl(string $accessToken, string $projectRef, string $databasePassword): ?string
    {
        try {
            $payload = $this->request(
                'GET',
                '/projects/'.rawurlencode($projectRef).'/config/database/pooler',
                $accessToken,
            )->json();
        } catch (SupabaseMirrorSetupException $exception) {
            if ($exception->reasonCode === 'invalid_token') {
                throw $exception;
            }

            return null;
        }

        $pooler = is_array($payload)
            ? collect($payload)->first(fn (mixed $value): bool => is_array($value)
                && ($value['database_type'] ?? '') === 'PRIMARY')
            : null;
        $template = is_array($pooler)
            ? trim((string) ($pooler['connection_string'] ?? $pooler['connectionString'] ?? ''))
            : '';
        if ($template === '') {
            return null;
        }

        $parseable = preg_replace('/:\[[^]]+]@/', ':password@', $template) ?? '';
        $parts = parse_url($parseable);
        $host = is_array($parts) ? mb_strtolower(trim((string) ($parts['host'] ?? ''))) : '';
        $user = is_array($parts) ? trim((string) ($parts['user'] ?? '')) : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 5432) : 0;

        if ($host === ''
            || ! str_ends_with($host, '.pooler.supabase.com')
            || $user !== 'postgres.'.$projectRef
            || ! in_array($port, [5432, 6543], true)) {
            return null;
        }

        // Supabase's Management API currently returns the shared pooler's
        // transaction URL on 6543. The same host uses 5432 for session mode,
        // which supports persistent clients and PostgreSQL tooling on IPv4.
        return $this->postgresUrl($user, $databasePassword, $host, 5432);
    }

    /** @param array{ref: string, database_host: string} $project */
    private function databaseHost(array $project): string
    {
        $host = mb_strtolower(trim($project['database_host']));

        if ($host !== '' && str_ends_with($host, '.supabase.co')) {
            return $host;
        }

        return 'db.'.mb_strtolower($project['ref']).'.supabase.co';
    }

    private function postgresUrl(string $username, string $password, string $host, int $port): string
    {
        return sprintf(
            'postgresql://%s:%s@%s:%d/postgres?sslmode=require&connect_timeout=8',
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
        );
    }
}
