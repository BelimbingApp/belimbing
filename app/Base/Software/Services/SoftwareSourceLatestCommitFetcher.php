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
     * @return array<string, array{0: array<string, mixed>|null, 1: string|null, 2: string|null}>
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
                        ->env($repo->environment(authenticated: true))
                        ->timeout(30)
                        ->command($repo->command([
                            'ls-remote',
                            '--exit-code',
                            'origin',
                            'refs/heads/'.$request['branch'],
                        ]));
                }
            });
        } catch (Throwable $exception) {
            return array_map(
                fn (array $request): array => [null, (string) __('Could not start Git remote status checks.'), $exception->getMessage()],
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
            if (! isset($metadataRequests[$key])) {
                continue;
            }

            $commit = null;

            if ($response instanceof Response && $response->successful()) {
                $payload = $response->json();
                $commit = is_array($payload)
                    ? $this->gitReader->githubCommit($metadataRequests[$key]['sha'], $payload)
                    : null;
            }

            if ($commit !== null) {
                $latest[$key] = [$commit, null, null];

                continue;
            }

            // The row is about to say the date is unavailable. Say why: this used
            // to drop the status code silently and leave the operator with two
            // words and no lead, on a row that is otherwise telling them to update.
            $latest[$key] = $this->withDateError(
                $latest[$key] ?? [null, null, null],
                $this->dateErrorFor($metadataRequests[$key], $response),
            );
        }

        return $latest;
    }

    /**
     * @param  array{0: array<string, mixed>|null, 1: string|null, 2: string|null}  $entry
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: string|null}
     */
    private function withDateError(array $entry, string $reason): array
    {
        if (! is_array($entry[0])) {
            return $entry;
        }

        $entry[0]['date_error'] = $reason;

        return $entry;
    }

    /**
     * @param  array{owner: string, name: string, sha: string, token: string|null}  $request
     */
    private function dateErrorFor(array $request, Response|Throwable|null $response): string
    {
        $repo = $request['owner'].'/'.$request['name'];
        $hasToken = is_string($request['token']) && $request['token'] !== '';

        if ($response instanceof Response
            && in_array($response->status(), [401, 403, 404], true)
            && ! $hasToken) {
            return (string) __('GitHub returned :status for :repo and no token is stored for :owner — add one in GitHub Access.', [
                'status' => $response->status(),
                'repo' => $repo,
                'owner' => $request['owner'],
            ]);
        }

        if ($response instanceof Response) {
            return (string) __('GitHub returned :status for the commit metadata of :repo.', [
                'status' => $response->status(),
                'repo' => $repo,
            ]);
        }

        if ($response instanceof Throwable) {
            return (string) __('Could not reach GitHub for the commit metadata of :repo: :error.', [
                'repo' => $repo,
                'error' => $response->getMessage(),
            ]);
        }

        return (string) __('The commit is not in this checkout and GitHub returned no metadata for :repo.', ['repo' => $repo]);
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
