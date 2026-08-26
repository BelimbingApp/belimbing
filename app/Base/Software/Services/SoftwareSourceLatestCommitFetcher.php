<?php

namespace App\Base\Software\Services;

use App\Base\Support\Git\GitRepository;
use App\Base\Support\Git\GitResult;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Resolves each requested branch's latest commit: a fast `git ls-remote` for the
 * SHA, then GitHub API metadata (with an authenticated retry) for whatever the
 * local object database can't already supply.
 */
final class SoftwareSourceLatestCommitFetcher
{
    public function __construct(
        private readonly SoftwareSourceGitReader $gitReader,
        private readonly GitHubTokenStore $tokens,
    ) {}

    /**
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $requests
     * @return array<string, array{0: array<string, mixed>|null, 1: string|null}>
     */
    public function fetchLatestCommits(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        try {
            $poolResults = Process::concurrently(function ($pool) use ($requests): void {
                foreach ($requests as $key => $request) {
                    $repo = new GitRepository($request['path'], $this->tokens->tokenFor($request['owner']));

                    $pool->as($key)
                        ->path($request['path'])
                        ->env(['GIT_TERMINAL_PROMPT' => '0'])
                        ->timeout(30)
                        ->command($repo->command([
                            'ls-remote',
                            '--exit-code',
                            'origin',
                            'refs/heads/'.$request['branch'],
                        ], authenticated: true));
                }
            });
        } catch (Throwable $exception) {
            return array_map(
                fn (array $request): array => [null, (string) __('Could not start Git remote status checks: :error', ['error' => $exception->getMessage()])],
                $requests,
            );
        }

        $latest = [];
        foreach ($requests as $key => $request) {
            $result = $poolResults[$key] ?? null;
            $gitResult = $result !== null
                ? new GitResult(
                    ok: $result->successful(),
                    output: trim($result->output()),
                    error: trim($result->errorOutput()),
                    exitCode: $result->exitCode() ?? -1,
                )
                : new GitResult(ok: false, output: '', error: (string) __('git process did not return a result'), exitCode: -1);

            $latest[$key] = $this->gitReader->parseLatestCommitResult(
                $request['path'],
                $request['owner'],
                $request['name'],
                $request['branch'],
                $gitResult,
            );
        }

        return $this->resolveLatestCommitMetadata($latest, $requests);
    }

    /**
     * `git ls-remote` returns the remote SHA without transferring its commit object.
     * Preserve that fast check, then ask GitHub only for metadata that is not already
     * available from the local object database.
     *
     * @param  array<string, array{0: array<string, mixed>|null, 1: string|null}>  $latest
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $requests
     * @return array<string, array{0: array<string, mixed>|null, 1: string|null}>
     */
    private function resolveLatestCommitMetadata(array $latest, array $requests): array
    {
        $metadataRequests = $this->metadataRequestsNeedingDate($latest, $requests);

        if ($metadataRequests === []) {
            return $latest;
        }

        $responses = $this->requestLatestCommitMetadataWithAuthRetry($metadataRequests);

        return $this->applyLatestCommitMetadataResponses($latest, $responses, $metadataRequests);
    }

    /**
     * @param  array<string, array{0: array<string, mixed>|null, 1: string|null}>  $latest
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $requests
     * @return array<string, array{owner: string, name: string, sha: string, token: string|null}>
     */
    private function metadataRequestsNeedingDate(array $latest, array $requests): array
    {
        $metadataRequests = [];

        foreach ($latest as $key => [$commit]) {
            if (! is_array($commit) || ($commit['date'] ?? null) !== null || ! isset($requests[$key])) {
                continue;
            }

            $request = $requests[$key];
            $metadataRequests[$key] = [
                'owner' => $request['owner'],
                'name' => $request['name'],
                'sha' => (string) $commit['sha'],
                'token' => $this->tokens->tokenFor($request['owner']),
            ];
        }

        return $metadataRequests;
    }

    /**
     * @param  array<string, array{owner: string, name: string, sha: string, token: string|null}>  $metadataRequests
     * @return array<string, Response|Throwable>
     */
    private function requestLatestCommitMetadataWithAuthRetry(array $metadataRequests): array
    {
        $responses = $this->requestLatestCommitMetadata($metadataRequests);
        $authenticatedRequests = [];

        foreach ($responses as $key => $response) {
            $token = $metadataRequests[$key]['token'] ?? null;

            if ($response instanceof Response
                && in_array($response->status(), [401, 403, 404], true)
                && is_string($token)
                && $token !== '') {
                $authenticatedRequests[$key] = $metadataRequests[$key];
            }
        }

        if ($authenticatedRequests === []) {
            return $responses;
        }

        return array_replace(
            $responses,
            $this->requestLatestCommitMetadata($authenticatedRequests, authenticated: true),
        );
    }

    /**
     * @param  array<string, array{0: array<string, mixed>|null, 1: string|null}>  $latest
     * @param  array<string, Response|Throwable>  $responses
     * @param  array<string, array{owner: string, name: string, sha: string, token: string|null}>  $metadataRequests
     * @return array<string, array{0: array<string, mixed>|null, 1: string|null}>
     */
    private function applyLatestCommitMetadataResponses(array $latest, array $responses, array $metadataRequests): array
    {
        foreach ($responses as $key => $response) {
            if (! $response instanceof Response || ! $response->successful() || ! isset($metadataRequests[$key])) {
                continue;
            }

            $payload = $response->json();
            $commit = is_array($payload)
                ? $this->gitReader->githubCommit($metadataRequests[$key]['sha'], $payload)
                : null;

            if ($commit !== null) {
                $latest[$key] = [$commit, null];
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, array{owner: string, name: string, sha: string, token: string|null}>  $requests
     * @return array<string, Response|Throwable>
     */
    private function requestLatestCommitMetadata(array $requests, bool $authenticated = false): array
    {
        return Http::pool(function (Pool $pool) use ($requests, $authenticated): void {
            foreach ($requests as $key => $request) {
                $pending = $pool->as($key)
                    ->acceptJson()
                    ->withUserAgent('Belimbing Update Checker')
                    ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                    ->timeout(15);

                if ($authenticated && $request['token'] !== null) {
                    $pending->withToken($request['token']);
                }

                $pending->get(sprintf(
                    'https://api.github.com/repos/%s/%s/commits/%s',
                    rawurlencode($request['owner']),
                    rawurlencode($request['name']),
                    rawurlencode($request['sha']),
                ));
            }
        });
    }
}
