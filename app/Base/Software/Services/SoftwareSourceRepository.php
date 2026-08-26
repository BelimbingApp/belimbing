<?php

namespace App\Base\Software\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Git\GitRepository;
use App\Base\Support\Str;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Discovers git-backed software sources and reads their local/remote state.
 */
class SoftwareSourceRepository
{
    private const REMOTE_STATUS_CACHE_SECONDS = 60;

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
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null, error_detail: string|null}>
     */
    public function status(bool $useRemoteCache = true, bool $includeRemote = true): array
    {
        $entries = [];
        $latestRequests = [];
        $latestRequestAliases = [];
        $requestKeyByCacheKey = [];

        foreach ($this->sources() as $source) {
            [$owner, $name, $remoteError] = $this->gitReader->remoteIdentity($source['path']);
            $snapshot = $this->gitReader->localSnapshot($source['path']);
            $branch = $snapshot['branch'] ?? 'main';

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
                'up_to_date' => null,
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

        $this->applyLatestCommitResults($entries, $latestRequests, $latestRequestAliases, $latestResults);

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
     * @return list<array{owner: string, repos: list<string>, has_token: bool}>
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
            $byOwner[$owner]['repos'][] = $owner.'/'.$name;
        }

        return array_values(array_map(function (array $entry): array {
            $entry['has_token'] = $this->tokenFor($entry['owner']) !== null;

            return $entry;
        }, $byOwner));
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

            if ($entry['up_to_date'] === true) {
                $lines[] = (string) __('Verified: :label is at :current and matches :branch.', [
                    'label' => $target['label'],
                    'current' => $entry['current']['short'] ?? __('unknown'),
                    'branch' => $entry['branch'] ?? __('the selected branch'),
                ]);

                continue;
            }

            if ($entry['up_to_date'] === false) {
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
     * @param  array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null, error_detail: string|null}  $entry
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
            $this->applyLatestCommit($entry, $cached[0] ?? null, $cached[1] ?? null, $cached[2] ?? null);

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
     * @param  array<string, array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null, error_detail: string|null}>  $entries
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $latestRequests
     * @param  array<string, string>  $latestRequestAliases
     * @param  array<string, array{0: array<string, mixed>|null, 1: string|null, 2: string|null}>  $latestResults
     */
    private function applyLatestCommitResults(array &$entries, array $latestRequests, array $latestRequestAliases, array $latestResults): void
    {
        foreach ($latestResults as $key => $latestResult) {
            if (! isset($entries[$key])) {
                continue;
            }

            [$latest, $error, $detail] = array_pad($latestResult, 3, null);
            $this->applyLatestCommit($entries[$key], $latest, $error, $detail);

            if ($latest !== null && ($latestRequests[$key]['use_cache'] ?? false) === true) {
                $cacheKey = (string) $latestRequests[$key]['cache_key'];

                $this->latestCommitRuntimeCache[$cacheKey] = [$latest, $error, $detail];
                Cache::put($cacheKey, [$latest, $error, $detail], self::REMOTE_STATUS_CACHE_SECONDS);
            }
        }

        foreach ($latestRequestAliases as $key => $sourceKey) {
            if (! isset($entries[$key], $latestResults[$sourceKey])) {
                continue;
            }

            [$latest, $error, $detail] = array_pad($latestResults[$sourceKey], 3, null);
            $this->applyLatestCommit($entries[$key], $latest, $error, $detail);
        }
    }

    /**
     * @param  array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null, error_detail: string|null}  $entry
     * @param  array<string, mixed>|null  $latest
     */
    private function applyLatestCommit(array &$entry, ?array $latest, ?string $error, ?string $errorDetail = null): void
    {
        $entry['error_detail'] = $errorDetail;

        if ($latest === null) {
            $entry['error'] = $error;

            return;
        }

        $entry['latest'] = $latest;
        $entry['up_to_date'] = $entry['current'] !== null && $entry['current']['sha'] === $latest['sha'];
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
