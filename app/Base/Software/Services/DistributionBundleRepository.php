<?php

namespace App\Base\Software\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Git\GitRepository;
use App\Base\Support\Git\GitResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Discovers git-backed Distribution Bundles and reads their local/remote state.
 */
class DistributionBundleRepository
{
    private const TOKEN_PREFIX = 'integrations.github.token.';

    private const REMOTE_STATUS_CACHE_SECONDS = 60;

    /**
     * @var array<string, array{0: array<string, mixed>|null, 1: string|null}>
     */
    private array $latestCommitRuntimeCache = [];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function status(bool $useRemoteCache = true, bool $includeRemote = true): array
    {
        $entries = [];
        $latestRequests = [];
        $latestRequestAliases = [];
        $requestKeyByCacheKey = [];

        foreach ($this->distributions() as $dist) {
            [$owner, $name, $remoteError] = $this->remoteIdentity($dist['path']);
            $snapshot = $this->localSnapshot($dist['path']);
            $branch = $snapshot['branch'] ?? 'main';

            $entries[$dist['key']] = [
                'key' => $dist['key'],
                'label' => $dist['label'],
                'path' => $dist['relative'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $branch,
                'working_tree' => $snapshot['working_tree'],
                'current' => $snapshot['current'],
                'latest' => null,
                'up_to_date' => null,
                'error' => null,
            ];

            if ($owner === null) {
                $entries[$dist['key']]['error'] = $remoteError ?? (string) __('No GitHub origin remote.');

                continue;
            }

            if (! $includeRemote) {
                continue;
            }

            $cacheKey = $this->latestCommitCacheKey($owner, $name, $branch);
            $cached = $useRemoteCache
                ? ($this->latestCommitRuntimeCache[$cacheKey] ?? Cache::get($cacheKey))
                : null;

            if (is_array($cached)) {
                $this->applyLatestCommit($entries[$dist['key']], $cached[0] ?? null, $cached[1] ?? null);

                continue;
            }

            if (isset($requestKeyByCacheKey[$cacheKey])) {
                $latestRequestAliases[$dist['key']] = $requestKeyByCacheKey[$cacheKey];

                continue;
            }

            $requestKeyByCacheKey[$cacheKey] = $dist['key'];
            $latestRequests[$dist['key']] = [
                'path' => $dist['path'],
                'owner' => $owner,
                'name' => $name,
                'branch' => $branch,
                'cache_key' => $cacheKey,
                'use_cache' => $useRemoteCache,
            ];
        }

        $latestResults = $this->fetchLatestCommits($latestRequests);

        foreach ($latestResults as $key => $latestResult) {
            if (! isset($entries[$key])) {
                continue;
            }

            [$latest, $error] = $latestResult;
            $this->applyLatestCommit($entries[$key], $latest, $error);

            if ($latest !== null && ($latestRequests[$key]['use_cache'] ?? false) === true) {
                $this->latestCommitRuntimeCache[(string) $latestRequests[$key]['cache_key']] = [$latest, $error];

                Cache::put(
                    (string) $latestRequests[$key]['cache_key'],
                    [$latest, $error],
                    self::REMOTE_STATUS_CACHE_SECONDS,
                );
            }
        }

        foreach ($latestRequestAliases as $key => $sourceKey) {
            if (! isset($entries[$key], $latestResults[$sourceKey])) {
                continue;
            }

            [$latest, $error] = $latestResults[$sourceKey];
            $this->applyLatestCommit($entries[$key], $latest, $error);
        }

        return array_values($entries);
    }

    /**
     * Per-bundle LOCAL state only — no network calls, unlike status(). The Software
     * Inventory read model reports what is really on disk (branch, working tree,
     * current commit) without paying a GitHub round-trip per bundle on every render.
     *
     * @return list<array{key: string, label: string, path: string, absolutePath: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null}>
     */
    public function localStatus(bool $includePlatformWorkingTree = true, int $gitTimeoutSeconds = 60): array
    {
        return array_map(function (array $dist) use ($includePlatformWorkingTree, $gitTimeoutSeconds): array {
            $repo = new GitRepository($dist['path']);
            $isRepo = $repo->isRepository();
            $readWorkingTree = $isRepo && ($includePlatformWorkingTree || $dist['key'] !== 'platform');
            [$owner, $name] = $isRepo
                ? $this->remoteIdentity($dist['path'], timeout: $gitTimeoutSeconds)
                : [null, null];
            $snapshot = $this->localSnapshot($dist['path'], readWorkingTree: $readWorkingTree, timeout: $gitTimeoutSeconds);

            return [
                'key' => $dist['key'],
                'label' => $dist['label'],
                'path' => $dist['relative'],
                'absolutePath' => $dist['path'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $snapshot['branch'],
                'working_tree' => $snapshot['working_tree'],
                'current' => $snapshot['current'],
            ];
        }, $this->distributions());
    }

    /**
     * @return list<array{owner: string, repos: list<string>, has_token: bool}>
     */
    public function owners(): array
    {
        $byOwner = [];

        foreach ($this->distributions() as $dist) {
            [$owner, $name] = $this->remoteIdentity($dist['path']);

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

        foreach ($this->distributions() as $dist) {
            [$repoOwner, $name] = $this->remoteIdentity($dist['path']);

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
        $token = $this->settings->get(self::TOKEN_PREFIX.strtolower($owner));

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function saveToken(string $owner, string $token): void
    {
        $this->settings->set(self::TOKEN_PREFIX.strtolower($owner), trim($token), encrypted: true);
    }

    /**
     * @param  array{label: string, path: string}  $dist
     */
    public function pull(array $dist): string
    {
        [$owner] = $this->remoteIdentity($dist['path']);
        $token = $owner !== null ? $this->tokenFor($owner) : null;

        $result = (new GitRepository($dist['path'], $token))->pull();

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
                'label' => $dist['label'],
                'path' => $dist['path'],
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
    public function distributions(): array
    {
        // Module-level git roots (app/Modules/{Domain}/{Module}) make a future slot
        // implementation — a whole module shipped as its own repo nested inside a
        // domain — visible alongside domain-level roots. isRepositoryPath() checks for
        // a `.git` at the path itself, so a plain module folder inside a domain
        // checkout (no nested `.git`) is never mistaken for its own bundle.
        return [
            [
                'key' => 'platform',
                'label' => (string) __('Belimbing (platform)'),
                'path' => base_path(),
                'relative' => '.',
            ],
            ...$this->repositoriesIn('app/Modules/*', fn (string $dir): string => (string) __('Module: :name', ['name' => basename($dir)])),
            ...$this->repositoriesIn('app/Modules/*/*', fn (string $dir): string => (string) __('Module: :name', ['name' => basename(dirname($dir)).'/'.basename($dir)])),
            ...$this->extensionDistributions(),
        ];
    }

    /**
     * Git-backed distributions for directories matching a base-relative glob.
     *
     * @param  callable(string): string  $labeller
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function repositoriesIn(string $glob, callable $labeller): array
    {
        $found = [];

        foreach (glob(base_path($glob), GLOB_ONLYDIR) ?: [] as $dir) {
            if ($this->isRepositoryPath($dir)) {
                $found[] = $this->distribution($dir, $labeller($dir));
            }
        }

        return $found;
    }

    /**
     * Extensions are either a single repo at `extensions/{name}` or repos one level
     * deeper at `extensions/{group}/{name}`.
     *
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function extensionDistributions(): array
    {
        $found = [];

        foreach (glob(base_path('extensions/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $group = basename($dir);

            if ($this->isRepositoryPath($dir)) {
                $found[] = $this->distribution($dir, (string) __('Extension: :name', ['name' => $group]));

                continue;
            }

            $found = [
                ...$found,
                ...$this->repositoriesIn('extensions/'.$group.'/*', fn (string $sub): string => (string) __('Extension: :name', ['name' => $group.'/'.basename($sub)])),
            ];
        }

        return $found;
    }

    /**
     * @return array{key: string, label: string, path: string, relative: string}
     */
    private function distribution(string $path, string $label): array
    {
        $relative = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);

        return [
            'key' => str_replace(['/', '\\'], '-', $relative),
            'label' => $label,
            'path' => $path,
            'relative' => str_replace('\\', '/', $relative),
        ];
    }

    /**
     * @return array{branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null}
     */
    private function localSnapshot(string $path, bool $readWorkingTree = true, int $timeout = 60): array
    {
        $repo = new GitRepository($path);

        if (! $repo->isRepository()) {
            return [
                'branch' => null,
                'working_tree' => $this->cleanWorkingTree(),
                'current' => null,
            ];
        }

        $summary = $readWorkingTree ? $repo->statusSummary(timeout: $timeout) : null;

        return [
            'branch' => $summary['branch'] ?? $repo->currentBranch(timeout: $timeout) ?? 'main',
            'working_tree' => $readWorkingTree ? $this->workingTree($path, timeout: $timeout, summary: $summary) : $this->cleanWorkingTree(),
            'current' => $this->localCommit($path, timeout: $timeout),
        ];
    }

    /**
     * Local working-tree state for a bundle: count of uncommitted changes and how far
     * the branch is ahead of / behind its upstream. This is what makes changes another
     * tool writes into a bundle (e.g. schema incubation rewriting migration files)
     * visible — each bundle is its own nested git repo, so they never surface in the
     * platform repo's `git status`.
     *
     * @param  array{branch: string|null, dirty: int, ahead: int, behind: int}|null  $summary
     * @return array{dirty: int, ahead: int, behind: int}
     */
    private function workingTree(string $path, int $timeout = 60, ?array $summary = null): array
    {
        $repo = new GitRepository($path);
        $summary ??= $repo->statusSummary(timeout: $timeout);

        if ($summary !== null) {
            return [
                'dirty' => $summary['dirty'],
                'ahead' => $summary['ahead'],
                'behind' => $summary['behind'],
            ];
        }

        $aheadBehind = $repo->aheadBehind(timeout: $timeout);

        return [
            'dirty' => $repo->uncommittedCount(timeout: $timeout),
            'ahead' => $aheadBehind['ahead'],
            'behind' => $aheadBehind['behind'],
        ];
    }

    /**
     * @return array{dirty: int, ahead: int, behind: int}
     */
    private function cleanWorkingTree(): array
    {
        return ['dirty' => 0, 'ahead' => 0, 'behind' => 0];
    }

    private function localCommit(string $path, int $timeout = 60): ?array
    {
        $line = $this->git($path, ['log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'], timeout: $timeout);

        if ($line === null || $line === '') {
            return null;
        }

        [$sha, $date, $author, $subject] = array_pad(explode("\x1f", $line), 4, '');

        return $this->commit($sha, $date, $author, $subject);
    }

    private function latestCommitCacheKey(string $owner, string $name, string $branch): string
    {
        return 'software.bundle.latest.'.hash('sha256', strtolower($owner.'/'.$name).'|'.$branch);
    }

    /**
     * @param  array<string, array{path: string, owner: string, name: string, branch: string, cache_key: string, use_cache: bool}>  $requests
     * @return array<string, array{0: array<string, mixed>|null, 1: string|null}>
     */
    private function fetchLatestCommits(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        try {
            $poolResults = Process::concurrently(function ($pool) use ($requests): void {
                foreach ($requests as $key => $request) {
                    $repo = new GitRepository($request['path'], $this->tokenFor($request['owner']));

                    $pool->as($key)
                        ->path($request['path'])
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

            $latest[$key] = $this->parseLatestCommitResult(
                $request['path'],
                $request['owner'],
                $request['name'],
                $request['branch'],
                $gitResult,
            );
        }

        return $latest;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function parseLatestCommitResult(string $path, string $owner, string $name, string $branch, GitResult $result): array
    {
        $repo = "{$owner}/{$name}";

        if (! $result->ok) {
            return [null, $this->remoteCommitError($repo, $branch, $result)];
        }

        $sha = (string) strtok($result->output, " \t");

        if ($sha === '' || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1) {
            return [null, (string) __('Git remote response for :repo@:branch did not include a commit SHA.', ['repo' => $repo, 'branch' => $branch])];
        }

        return [$this->remoteCommit($path, $sha), null];
    }

    /**
     * @param  array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}  $entry
     * @param  array<string, mixed>|null  $latest
     */
    private function applyLatestCommit(array &$entry, ?array $latest, ?string $error): void
    {
        if ($latest === null) {
            $entry['error'] = $error;

            return;
        }

        $entry['latest'] = $latest;
        $entry['up_to_date'] = $entry['current'] !== null && $entry['current']['sha'] === $latest['sha'];
    }

    private function remoteCommit(string $path, string $sha): array
    {
        $line = $this->git($path, ['show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', $sha]);

        if ($line !== null && $line !== '') {
            [$commitSha, $date, $author, $subject] = array_pad(explode("\x1f", $line), 4, '');

            return $this->commit($commitSha, $date, $author, $subject);
        }

        return $this->commit($sha, '', '', (string) __('Remote branch head'));
    }

    private function remoteCommitError(string $repo, string $branch, GitResult $result): string
    {
        return (string) __('Could not read latest commit for :repo@:branch via git ls-remote (:detail). Public repositories do not need a token; check the repo name, branch, or network access. If this repo is private, add a token in GitHub Access.', [
            'repo' => $repo,
            'branch' => $branch,
            'detail' => $result->message(),
        ]);
    }

    /**
     * @return array{sha: string, short: string, date: string|null, ago: string|null, author: string, subject: string}
     */
    private function commit(string $sha, string $date, string $author, string $subject): array
    {
        $when = $date !== '' ? Carbon::parse($date) : null;

        return [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'date' => $when?->toIso8601String(),
            'ago' => $when?->diffForHumans(['parts' => 2]),
            'author' => $author,
            'subject' => $subject,
        ];
    }

    private function githubGet(string $owner, string $name, string $path, ?string $token): Response
    {
        $url = "/repos/{$owner}/{$name}{$path}";
        $base = Http::acceptJson()
            ->withUserAgent('Belimbing Update Checker')
            ->timeout(15)
            ->baseUrl('https://api.github.com');

        $response = $base->get($url);

        if (! $response->successful() && $token !== null && in_array($response->status(), [401, 403, 404], true)) {
            $response = $base->withToken($token)->get($url);
        }

        return $response;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function remoteIdentity(string $path, int $timeout = 60): array
    {
        $repo = new GitRepository($path);

        if (! $repo->isRepository()) {
            return [null, null, (string) __('No git repository found at :path.', ['path' => $path])];
        }

        $remoteUrl = $repo->configuredRemoteUrl('origin');

        if ($remoteUrl === null) {
            $remote = $repo->run(['remote', 'get-url', 'origin'], timeout: $timeout);

            if (! $remote->ok) {
                return [null, null, (string) __('Could not read Git origin remote for :path: :detail', [
                    'path' => $path,
                    'detail' => $remote->message(),
                ])];
            }

            $remoteUrl = $remote->output;
        }

        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches) === 1) {
            return [$matches[1], $matches[2], null];
        }

        return [null, null, (string) __('Git origin remote is not a GitHub repository: :remote', ['remote' => $remoteUrl])];
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $path, array $args, int $timeout = 60): ?string
    {
        return (new GitRepository($path))->output($args, timeout: $timeout);
    }

    private function isRepositoryPath(string $path): bool
    {
        return (new GitRepository($path))->isRepository();
    }
}
