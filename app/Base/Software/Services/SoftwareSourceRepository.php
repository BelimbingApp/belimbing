<?php

namespace App\Base\Software\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Git\GitRepository;
use App\Base\Support\Str;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Discovers git-backed software sources and reads their local/remote state.
 */
class SoftwareSourceRepository
{
    private const REMOTE_STATUS_CACHE_SECONDS = 60;

    // Deliberately shorter than the success TTL: a failure is often transient
    // (network blip, expired token) and the operator may fix it and want to see
    // that quickly, but it must still not re-run on every render/round-trip.
    private const REMOTE_STATUS_FAILURE_CACHE_SECONDS = 20;

    // Repo visibility (public/private) changes far less often than a commit
    // SHA; a longer TTL keeps GitHub Access from making a live GitHub call on
    // every render and every Livewire round-trip.
    private const OWNER_VISIBILITY_CACHE_SECONDS = 300;

    /**
     * @var array<string, array{0: array<string, mixed>|null, 1: string|null, 2: string|null}>
     */
    private array $latestCommitRuntimeCache = [];

    private readonly SoftwareSourceGitReader $gitReader;

    private readonly GitHubTokenStore $tokens;

    private readonly SoftwareSourceLatestCommitFetcher $commitFetcher;

    public function __construct(
        SettingsService $settings,
        ?SoftwareSourceGitReader $gitReader = null,
        ?GitHubTokenStore $tokens = null,
        ?SoftwareSourceLatestCommitFetcher $commitFetcher = null,
    ) {
        $this->gitReader = $gitReader ?? new SoftwareSourceGitReader;
        $this->tokens = $tokens ?? new GitHubTokenStore($settings);
        $this->commitFetcher = $commitFetcher ?? new SoftwareSourceLatestCommitFetcher($this->gitReader, $this->tokens);
    }

    /**
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, update_state: 'up_to_date'|'ahead'|'behind'|null, error: string|null, error_detail: string|null}>
     */
    public function status(bool $useRemoteCache = true, bool $includeRemote = true): array
    {
        $entries = [];
        $absolutePaths = [];
        $latestRequests = [];
        $latestRequestAliases = [];
        $requestKeyByCacheKey = [];

        foreach ($this->sources() as $source) {
            [$owner, $name, $remoteError] = $this->gitReader->remoteIdentity($source['path']);
            $snapshot = $this->gitReader->localSnapshot($source['path']);
            $branch = $snapshot['branch'] ?? 'main';

            $absolutePaths[$source['key']] = $source['path'];
            $entries[$source['key']] = [
                'key' => $source['key'],
                'label' => $source['label'],
                'path' => $source['relative'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $branch,
                'working_tree' => $snapshot['working_tree'],
                'current' => $snapshot['current'],
                'latest' => null,
                'update_state' => null,
                'error' => null,
                'error_detail' => null,
            ];

            if ($owner === null) {
                $entries[$source['key']]['error'] = $remoteError ?? (string) __('No GitHub origin remote.');

                continue;
            }

            if (! $includeRemote) {
                continue;
            }

            $this->queueLatestCommitRequest(
                $latestRequests,
                $latestRequestAliases,
                $requestKeyByCacheKey,
                $entries[$source['key']],
                [
                    'source' => $source,
                    'owner' => $owner,
                    'name' => $name,
                    'branch' => $branch,
                    'use_cache' => $useRemoteCache,
                ],
            );
        }

        $latestResults = $this->commitFetcher->fetchLatestCommits($latestRequests);

        $this->applyLatestCommitResults($entries, $absolutePaths, $latestRequests, $latestRequestAliases, $latestResults);

        return array_values($entries);
    }

    /**
     * Per-source LOCAL state only — no network calls, unlike status(). The Software
     * Inventory read model reports what is really on disk (branch, working tree,
     * current commit) without paying a GitHub round-trip per source on every render.
     *
     * @return list<array{key: string, label: string, path: string, absolutePath: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null}>
     */
    public function localStatus(bool $includePlatformWorkingTree = true, int $gitTimeoutSeconds = 60): array
    {
        return array_map(function (array $source) use ($includePlatformWorkingTree, $gitTimeoutSeconds): array {
            $readWorkingTree = $includePlatformWorkingTree || $source['key'] !== 'platform';
            [$owner, $name] = $this->gitReader->remoteIdentity($source['path'], timeout: $gitTimeoutSeconds);
            $snapshot = $this->gitReader->localSnapshot($source['path'], readWorkingTree: $readWorkingTree, timeout: $gitTimeoutSeconds);

            return [
                'key' => $source['key'],
                'label' => $source['label'],
                'path' => $source['relative'],
                'absolutePath' => $source['path'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $snapshot['branch'],
                'working_tree' => $snapshot['working_tree'],
                'current' => $snapshot['current'],
            ];
        }, $this->sources());
    }

    /**
     * @return list<array{owner: string, repos: list<array{repo: string, visibility: 'public'|'private'|'unknown'}>, has_token: bool, all_public: bool}>
     */
    public function owners(): array
    {
        $byOwner = [];

        foreach ($this->sources() as $source) {
            [$owner, $name] = $this->gitReader->remoteIdentity($source['path']);

            if ($owner === null) {
                continue;
            }

            $byOwner[$owner]['owner'] = $owner;
            $byOwner[$owner]['repos'][] = [
                'repo' => $owner.'/'.$name,
                'visibility' => $this->repoVisibility($owner, $name),
            ];
        }

        return array_values(array_map(function (array $entry): array {
            $entry['has_token'] = $this->tokenFor($entry['owner']) !== null;
            $entry['all_public'] = ! collect($entry['repos'])->contains(fn (array $repo): bool => $repo['visibility'] !== 'public');

            return $entry;
        }, $byOwner));
    }

    /**
     * Whether GitHub reports this repo as public and reachable without a token —
     * the same anonymous-vs-authenticated distinction testOwner() already probes
     * on demand, run automatically here so the page can tell a healthy public
     * source from one that actually needs credentials.
     *
     * Three-valued rather than a bool: a failed *request* (rate limit, outage,
     * connection failure) is not evidence the repo is private, and this call runs
     * on every render — this page is exactly the one an operator opens when
     * credentials are broken or the network is down, so it must not throw or lie
     * when GitHub itself is unreachable. 'unknown' leaves the owner out of
     * all_public without asserting the repo needs a token it may not.
     *
     * Cached: this runs on every render (owners() backs a #[Defer] component
     * that re-renders on each interaction). A confirmed public/private result is
     * cached for OWNER_VISIBILITY_CACHE_SECONDS, since visibility rarely changes;
     * an unknown result gets the shorter REMOTE_STATUS_FAILURE_CACHE_SECONDS so a
     * transient failure is retried soon rather than pinned for minutes — and so
     * repeated renders during a real outage don't themselves become the thing
     * that exhausts GitHub's 60-requests-per-hour anonymous rate limit.
     *
     * @return 'public'|'private'|'unknown'
     */
    private function repoVisibility(string $owner, string $name): string
    {
        $cacheKey = 'software.owner.visibility.'.hash('sha256', strtolower($owner.'/'.$name));
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = $this->githubGet($owner, $name, '', null);
        } catch (ConnectionException) {
            return 'unknown';
        }

        $visibility = match (true) {
            $response->successful() && $response->json('private') === false => 'public',
            // Anonymous access to a private (or nonexistent) repo is a 404 —
            // GitHub does not distinguish the two to an unauthenticated caller.
            $response->status() === 404 => 'private',
            // Anything else (403 rate-limited, 5xx, ...) is a request failure,
            // not a fact about the repo's visibility.
            default => 'unknown',
        };

        $ttl = $visibility === 'unknown' ? self::REMOTE_STATUS_FAILURE_CACHE_SECONDS : self::OWNER_VISIBILITY_CACHE_SECONDS;
        Cache::put($cacheKey, $visibility, $ttl);

        return $visibility;
    }

    /**
     * @return list<array{repo: string, ok: bool, status: int|null, message: string}>
     */
    public function testOwner(string $owner, ?string $token = null): array
    {
        $token = $token !== null && trim($token) !== '' ? trim($token) : $this->tokenFor($owner);
        $results = [];

        foreach ($this->sources() as $source) {
            [$repoOwner, $name] = $this->gitReader->remoteIdentity($source['path']);

            if ($repoOwner === null || strtolower($repoOwner) !== strtolower($owner)) {
                continue;
            }

            $response = $this->githubGet($repoOwner, $name, '', $token);

            $message = match (true) {
                $response->successful() && $response->json('private') => (string) __('Reachable (private).'),
                $response->successful() => (string) __('Reachable (public).'),
                $response->status() === 401 => (string) __('Token rejected (401) — check the value.'),
                $response->status() === 403 => (string) __('Forbidden (403) — the token lacks access to this repo.'),
                $response->status() === 404 => (string) __('Not found (404) — private repo and the token is missing or lacks Contents: Read.'),
                default => (string) __('Failed (HTTP :status).', ['status' => $response->status()]),
            };

            $results[] = [
                'repo' => "{$repoOwner}/{$name}",
                'ok' => $response->successful(),
                'status' => $response->status(),
                'message' => $message,
            ];
        }

        return $results;
    }

    public function tokenFor(string $owner): ?string
    {
        return $this->tokens->tokenFor($owner);
    }

    public function saveToken(string $owner, string $token): void
    {
        $this->tokens->saveToken($owner, $token);
    }

    /**
     * @param  array{label: string, path: string}  $source
     */
    public function pull(array $source): string
    {
        [$owner] = $this->gitReader->remoteIdentity($source['path']);
        $token = $owner !== null ? $this->tokenFor($owner) : null;

        $result = (new GitRepository($source['path'], $token))->pull();

        if ($result->ok) {
            return $result->output !== '' ? $result->output : (string) __('Already up to date.');
        }

        $error = $result->message();

        // A diverged checkout (local commits the remote doesn't have) can't be fast-forwarded.
        // That's an anomaly only a human should reconcile — auto-merge can conflict mid-deploy and
        // a hard reset would silently discard those commits — so surface one actionable line
        // instead of git's raw advice hints.
        if (str_contains($error, 'Not possible to fast-forward')) {
            return (string) __('FAILED: :label has diverged from its remote — its local checkout has commits that are not on the remote, so it cannot be fast-forwarded. Review them with `git -C :path log --oneline @{u}..HEAD`, reconcile manually, then retry.', [
                'label' => $source['label'],
                'path' => $source['path'],
            ]);
        }

        return (string) __('FAILED: :error', ['error' => $error]);
    }

    /**
     * @param  list<array{key: string, label: string}>  $targets
     * @return list<string>
     */
    public function verifyTargets(array $targets): array
    {
        $status = collect($this->status(useRemoteCache: false))->keyBy('key');
        $lines = [];

        foreach ($targets as $target) {
            $entry = $status->get($target['key']);

            if (! is_array($entry)) {
                $lines[] = (string) __('Could not verify :label after update; refresh the page to check its current status.', ['label' => $target['label']]);

                continue;
            }

            if ($entry['update_state'] === 'up_to_date') {
                $lines[] = (string) __('Verified: :label is at :current and matches :branch.', [
                    'label' => $target['label'],
                    'current' => $entry['current']['short'] ?? __('unknown'),
                    'branch' => $entry['branch'] ?? __('the selected branch'),
                ]);

                continue;
            }

            if ($entry['update_state'] === 'ahead') {
                $lines[] = (string) __('Ahead of remote: :label is at :current, which already contains :branch (:latest). No update was needed.', [
                    'label' => $target['label'],
                    'current' => $entry['current']['short'] ?? __('unknown'),
                    'branch' => $entry['branch'] ?? __('the selected branch'),
                    'latest' => $entry['latest']['short'] ?? __('unknown'),
                ]);

                continue;
            }

            if ($entry['update_state'] === 'behind') {
                $lines[] = (string) __('Still behind: :label is at :current, latest is :latest. The Update button remains because this checkout did not reach the GitHub branch head.', [
                    'label' => $target['label'],
                    'current' => $entry['current']['short'] ?? __('unknown'),
                    'latest' => $entry['latest']['short'] ?? __('unknown'),
                ]);

                continue;
            }

            $lines[] = (string) __('Could not verify :label after update: :error', [
                'label' => $target['label'],
                'error' => $entry['error'] ?? __('unknown status'),
            ]);
        }

        return $lines;
    }

    /**
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    public function sources(): array
    {
        // Domain roots are installable sources even when tests or local checkouts lack
        // `.git`; nested module roots still need `.git` to represent slot implementations.
        return [
            [
                'key' => 'platform',
                'label' => (string) __('Belimbing (platform)'),
                'path' => base_path(),
                'relative' => '.',
            ],
            ...$this->domainSources(),
            ...$this->repositoriesIn(
                ApplicationTopology::domainModulePattern(),
                fn (string $dir): string => (string) __('Module source: :name', ['name' => basename(dirname($dir)).'/'.basename($dir)]),
                fn (string $dir): string => 'module-'.Str::pascalToKebab(basename(dirname($dir))).'-'.Str::pascalToKebab(basename($dir)),
            ),
            ...$this->extensionSources(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function domainSources(): array
    {
        $found = [];

        foreach (glob(ApplicationTopology::domainPattern(), GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $found[] = $this->source(
                $dir,
                (string) __('Domain: :name', ['name' => $name]),
                'domain-'.Str::pascalToKebab($name),
            );
        }

        return $found;
    }

    /**
     * Git-backed sources for directories matching an absolute glob.
     *
     * @param  callable(string): string  $labeller
     * @param  callable(string): string  $keyer
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function repositoriesIn(string $glob, callable $labeller, callable $keyer): array
    {
        $found = [];

        foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $dir) {
            if ($this->gitReader->isRepositoryPath($dir)) {
                $found[] = $this->source($dir, $labeller($dir), $keyer($dir));
            }
        }

        return $found;
    }

    /**
     * Extensions are either a source repository at `app/Extensions/{Extension}`
     * or repositories one level deeper at `{Module}`.
     *
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function extensionSources(): array
    {
        $found = [];

        foreach (glob(ApplicationTopology::extensionSourcePattern(), GLOB_ONLYDIR) ?: [] as $dir) {
            $group = basename($dir);

            if ($this->gitReader->isRepositoryPath($dir)) {
                $found[] = $this->source(
                    $dir,
                    (string) __('Extension: :name', ['name' => $group]),
                    'extension-'.Str::pascalToKebab($group),
                );

                continue;
            }

            $found = [
                ...$found,
                ...$this->repositoriesIn(
                    $dir.DIRECTORY_SEPARATOR.'*',
                    fn (string $sub): string => (string) __('Extension: :name', ['name' => $group.'/'.basename($sub)]),
                    fn (string $sub): string => 'extension-'.Str::pascalToKebab($group).'-'.Str::pascalToKebab(basename($sub)),
                ),
            ];
        }

        return $found;
    }

    /**
     * @return array{key: string, label: string, path: string, relative: string}
     */
    private function source(string $path, string $label, string $key): array
    {
        $relative = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);

        return [
            'key' => $key,
            'label' => $label,
            'path' => $path,
            'relative' => str_replace('\\', '/', $relative),
        ];
    }

    /**
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $latestRequests
     * @param  array<string, string>  $latestRequestAliases
     * @param  array<string, string>  $requestKeyByCacheKey
     * @param  array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, update_state: 'up_to_date'|'ahead'|'behind'|null, error: string|null, error_detail: string|null}  $entry
     * @param  array{source: array{key: string, label: string, path: string, relative: string}, owner: string, name: string, branch: string, use_cache: bool}  $request
     */
    private function queueLatestCommitRequest(
        array &$latestRequests,
        array &$latestRequestAliases,
        array &$requestKeyByCacheKey,
        array &$entry,
        array $request,
    ): void {
        $source = $request['source'];
        $cacheKey = 'software.source.latest.'.hash('sha256', strtolower($request['owner'].'/'.$request['name']).'|'.$request['branch']);
        $cached = $request['use_cache']
            ? ($this->latestCommitRuntimeCache[$cacheKey] ?? Cache::get($cacheKey))
            : null;

        if (is_array($cached)) {
            $this->applyLatestCommit($entry, $cached[0] ?? null, $cached[1] ?? null, $cached[2] ?? null, $source['path']);

            return;
        }

        if (isset($requestKeyByCacheKey[$cacheKey])) {
            $latestRequestAliases[$source['key']] = $requestKeyByCacheKey[$cacheKey];

            return;
        }

        $requestKeyByCacheKey[$cacheKey] = $source['key'];
        $latestRequests[$source['key']] = [
            'path' => $source['path'],
            'owner' => $request['owner'],
            'name' => $request['name'],
            'branch' => $request['branch'],
            'cache_key' => $cacheKey,
            'use_cache' => $request['use_cache'],
        ];
    }

    /**
     * @param  array<string, array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, update_state: 'up_to_date'|'ahead'|'behind'|null, error: string|null, error_detail: string|null}>  $entries
     * @param  array<string, string>  $absolutePaths  keyed by source key, same universe as $entries
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $latestRequests
     * @param  array<string, string>  $latestRequestAliases
     * @param  array<string, array{0: array<string, mixed>|null, 1: string|null, 2: string|null}>  $latestResults
     */
    private function applyLatestCommitResults(array &$entries, array $absolutePaths, array $latestRequests, array $latestRequestAliases, array $latestResults): void
    {
        foreach ($latestResults as $key => $latestResult) {
            if (! isset($entries[$key])) {
                continue;
            }

            [$latest, $error, $detail] = array_pad($latestResult, 3, null);
            $this->applyLatestCommit($entries[$key], $latest, $error, $detail, $absolutePaths[$key] ?? null);

            if (($latestRequests[$key]['use_cache'] ?? false) === true) {
                $cacheKey = (string) $latestRequests[$key]['cache_key'];
                $ttl = $latest !== null ? self::REMOTE_STATUS_CACHE_SECONDS : self::REMOTE_STATUS_FAILURE_CACHE_SECONDS;

                $this->latestCommitRuntimeCache[$cacheKey] = [$latest, $error, $detail];
                Cache::put($cacheKey, [$latest, $error, $detail], $ttl);
            }
        }

        foreach ($latestRequestAliases as $key => $sourceKey) {
            if (! isset($entries[$key], $latestResults[$sourceKey])) {
                continue;
            }

            [$latest, $error, $detail] = array_pad($latestResults[$sourceKey], 3, null);
            $this->applyLatestCommit($entries[$key], $latest, $error, $detail, $absolutePaths[$key] ?? null);
        }
    }

    /**
     * @param  array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, update_state: 'up_to_date'|'ahead'|'behind'|null, error: string|null, error_detail: string|null}  $entry
     * @param  array<string, mixed>|null  $latest
     */
    private function applyLatestCommit(array &$entry, ?array $latest, ?string $error, ?string $errorDetail, ?string $path): void
    {
        $entry['error_detail'] = $errorDetail;

        if ($latest === null) {
            $entry['error'] = $error;

            return;
        }

        $entry['latest'] = $latest;
        $entry['update_state'] = $this->updateState($entry, $latest, $path);
    }

    /**
     * @param  array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, update_state: 'up_to_date'|'ahead'|'behind'|null, error: string|null}  $entry
     * @param  array<string, mixed>  $latest
     * @return 'up_to_date'|'ahead'|'behind'
     */
    private function updateState(array &$entry, array $latest, ?string $path): string
    {
        $current = $entry['current'];

        if ($current !== null && $current['sha'] === $latest['sha']) {
            $entry['working_tree']['ahead'] = 0;
            $entry['working_tree']['behind'] = 0;

            return 'up_to_date';
        }

        // The remote SHA's object is local in exactly two cases: the checkout is
        // ahead (its own history contains that object), or it was already fetched
        // for other reasons. Either way, derive the true counts from it rather than
        // the tracking ref working_tree already carries, which is only as fresh as
        // the last fetch and can disagree with this live ls-remote result. A
        // checkout genuinely behind has never fetched the newer remote commit, so
        // the object is absent and we fall back to that tracking-ref estimate.
        $live = $path !== null ? $this->gitReader->liveAheadBehind($path, (string) $latest['sha']) : null;

        if ($live === null) {
            return 'behind';
        }

        $entry['working_tree']['ahead'] = $live['ahead'];
        $entry['working_tree']['behind'] = $live['behind'];

        return $live['behind'] === 0 ? 'ahead' : 'behind';
    }

    private function githubGet(string $owner, string $name, string $path, ?string $token): Response
    {
        $url = "/repos/{$owner}/{$name}{$path}";
        $base = Http::acceptJson()
            ->withUserAgent('Belimbing Update Checker')
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->timeout(15)
            ->baseUrl('https://api.github.com');

        $response = $base->get($url);

        if (! $response->successful() && $token !== null && in_array($response->status(), [401, 403, 404], true)) {
            $response = $base->withToken($token)->get($url);
        }

        return $response;
    }
}
