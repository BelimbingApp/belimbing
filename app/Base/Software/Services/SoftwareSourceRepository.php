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

    /**
     * @var array<string, array{0: string|null, 1: string|null, 2: string|null, 3: string|null}>
     */
    private array $upstreamRuntimeCache = [];

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
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, update_state: 'up_to_date'|'ahead'|'behind'|null, error: string|null, error_detail: string|null, upstream: array{remote: string, repo: string|null, branch: string|null, head: array<string, mixed>|null, mirror: array{state: 'missing'|'current'|'behind'|'diverged'|'unknown', sha: string|null, ahead: int|null, behind: int|null, reason: string|null}, stable: array{state: 'contained'|'behind'|'unknown', missing: int|null, fork_own: int|null, reason: string|null}, error: string|null, error_detail: string|null}|null}>
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
                'upstream' => null,
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

        if ($includeRemote) {
            $this->applyUpstreamStatus($entries, $absolutePaths, $useRemoteCache);
        }

        return array_values($entries);
    }

    /**
     * Read-only visibility of the release flow's two transitions (#374, was #344):
     *
     *   upstream/<branch>  ->  origin/<branch> mirror  ->  origin/master stable
     *
     * Remote branch heads are the source of truth for BOTH halves of the
     * requirement: the head values come from live ls-remote (never the
     * installed checkout's HEAD), and the branch identities are fixed — the
     * mirror is origin/<upstream branch>, the stable is origin/master
     * (UpstreamSyncService::STABLE_BRANCH) regardless of which branch the
     * local checkout happens to have checked out (sol's P1 on #395: reading
     * the branch name from localSnapshot() satisfied the HEAD half while
     * silently substituting the local checkout's branch identity).
     *
     * Ancestry and counts come from the local object database and degrade to
     * a stated unknown when objects were never fetched. No upstream remote is
     * a normal state: the entry stays null and the page renders as before.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @param  array<string, string>  $absolutePaths
     */
    private function applyUpstreamStatus(array &$entries, array $absolutePaths, bool $useRemoteCache): void
    {
        if (! isset($entries['platform'], $absolutePaths['platform'])) {
            return;
        }

        $path = $absolutePaths['platform'];
        $identity = $this->gitReader->upstreamIdentity($path);

        if ($identity === null) {
            return;
        }

        [$branch, $head, $error, $detail] = $this->upstreamHead($path, $identity, $useRemoteCache);

        // Lookups against origin authenticate with origin's own token — a
        // private deployment fork must not read as Unknown just because the
        // mirror probe went out anonymously (sol's P1 on #395). The upstream
        // probe keeps the upstream owner's token, resolved in upstreamHead().
        $originToken = ($entries['platform']['owner'] ?? null) !== null
            ? $this->tokenFor((string) $entries['platform']['owner'])
            : null;

        // With no configured branch and an unreachable upstream, the mirror
        // branch's NAME is unknowable — a distinct condition from a failed
        // existence lookup, and the reason must say which one happened.
        $mirror = $branch !== null
            ? $this->originBranchHead($path, $branch, $originToken, $useRemoteCache)
            : ['no-branch', null, null];
        $stable = $this->originBranchHead($path, UpstreamSyncService::STABLE_BRANCH, $originToken, $useRemoteCache);

        $repo = new GitRepository($path);
        $upstream = [
            'remote' => $identity['remote'],
            'repo' => $identity['repo'],
            'branch' => $branch,
            'head' => $head,
            'mirror' => $this->mirrorRelationship($repo, $identity['remote'], $head['sha'] ?? null, $mirror),
            'stable' => $this->stableRelationship($repo, $mirror, $stable),
            'error' => $error,
            'error_detail' => $detail ?? ($mirror[0] === 'error' ? $mirror[2] : null),
        ];

        $entries['platform']['upstream'] = $upstream;
    }

    /**
     * Relationship 1: the origin mirror branch vs the upstream head — missing /
     * current / behind (fast-forwardable, count) / diverged (both counts) /
     * unknown with a reason.
     *
     * @param  array{0: 'present'|'absent'|'error', 1: string|null, 2: string|null}  $mirror
     * @return array{state: string, sha: string|null, ahead: int|null, behind: int|null, reason: string|null}
     */
    private function mirrorRelationship(GitRepository $repo, string $remote, ?string $upstreamSha, array $mirror): array
    {
        $out = ['state' => 'unknown', 'sha' => $mirror[1], 'ahead' => null, 'behind' => null, 'reason' => null];

        if ($mirror[0] === 'no-branch') {
            $out['reason'] = (string) __('The upstream head could not be read, so the mirror branch is not known.');
        } elseif ($mirror[0] === 'error') {
            $out['reason'] = (string) __('Could not determine whether the mirror branch exists on origin.');
        } elseif ($mirror[0] === 'absent') {
            // Explicitly a state, not an error: the mirror has simply never
            // been created (#374 acceptance).
            $out['state'] = 'missing';
        } elseif ($upstreamSha === null) {
            $out['reason'] = (string) __('The upstream head could not be read, so the mirror cannot be compared.');
        } elseif ($mirror[1] === $upstreamSha) {
            $out['state'] = 'current';
            $out['ahead'] = 0;
            $out['behind'] = 0;
        } else {
            // base = upstream head, tip = mirror: `behind` counts commits only
            // upstream has (what a fast-forward would bring), `ahead` commits
            // only the mirror has (a broken mirror — someone committed to it).
            $counts = $repo->aheadBehindBetween($upstreamSha, (string) $mirror[1]);

            if ($counts === null) {
                $out['reason'] = (string) __('Commits are not in this checkout yet — fetch :remote to compare histories.', ['remote' => $remote]);
            } else {
                $out['ahead'] = $counts['ahead'];
                $out['behind'] = $counts['behind'];
                $out['state'] = $counts['ahead'] === 0 ? 'behind' : 'diverged';
            }
        }

        return $out;
    }

    /**
     * Relationship 2: origin/master stable vs the mirror — contained (no RC
     * needed) / behind (mirror commits stable lacks, the "updates available"
     * count; the fork's own commits are information, not divergence) /
     * unknown with a reason.
     *
     * @param  array{0: 'present'|'absent'|'error', 1: string|null, 2: string|null}  $mirror
     * @param  array{0: 'present'|'absent'|'error', 1: string|null, 2: string|null}  $stable
     * @return array{state: string, missing: int|null, fork_own: int|null, reason: string|null}
     */
    private function stableRelationship(GitRepository $repo, array $mirror, array $stable): array
    {
        $out = ['state' => 'unknown', 'missing' => null, 'fork_own' => null, 'reason' => null];

        if ($mirror[0] === 'absent') {
            $out['reason'] = (string) __('No mirror branch exists yet — refresh the mirror to create it, then compare.');
        } elseif ($mirror[1] === null) {
            $out['reason'] = (string) __('The mirror head is unavailable, so the stable branch cannot be compared.');
        } elseif ($stable[1] === null) {
            $out['reason'] = (string) __('The stable head could not be read from origin, so the stable-to-mirror relationship is unknown.');
        } elseif ($mirror[1] === $stable[1]) {
            $out['state'] = 'contained';
            $out['missing'] = 0;
            $out['fork_own'] = 0;
        } else {
            // base = mirror, tip = stable: `behind` counts mirror commits the
            // stable branch lacks — the "updates available" number an RC cut
            // would integrate — and `ahead` the fork's own commits.
            $counts = $repo->aheadBehindBetween((string) $mirror[1], (string) $stable[1]);

            if ($counts === null) {
                $out['reason'] = (string) __('Commits are not in this checkout yet — fetch origin to compare histories.');
            } else {
                $out['missing'] = $counts['behind'];
                $out['fork_own'] = $counts['ahead'];
                $out['state'] = $counts['behind'] === 0 ? 'contained' : 'behind';
            }
        }

        return $out;
    }

    /**
     * A named branch's head on origin, tri-state and cached: 'present' with
     * the SHA, 'absent' (exit 2 — a normal state), or 'error' (a failed
     * lookup is not a fact about the repository, #356). Authenticated with
     * origin's token so private forks resolve.
     *
     * @return array{0: 'present'|'absent'|'error', 1: string|null, 2: string|null} [state, sha, detail]
     */
    private function originBranchHead(string $path, string $branch, ?string $token, bool $useRemoteCache): array
    {
        $cacheKey = 'software.source.originhead.'.hash('sha256', $branch);
        $cached = $useRemoteCache
            ? ($this->upstreamRuntimeCache[$cacheKey] ?? Cache::get($cacheKey))
            : null;

        if (is_array($cached)) {
            return [$cached[0], $cached[1], $cached[2]];
        }

        $result = (new GitRepository($path, $token))->lsRemoteHead($branch);

        if ($result->ok) {
            $sha = (string) strtok($result->output, " \t");
            $tuple = preg_match('/^[a-f0-9]{40}$/i', $sha) === 1
                ? ['present', $sha, null]
                : ['error', null, $result->output];
        } elseif ($result->exitCode === 2) {
            $tuple = ['absent', null, null];
        } else {
            $tuple = ['error', null, $result->message()];
        }

        if ($useRemoteCache) {
            $ttl = $tuple[0] !== 'error' ? self::REMOTE_STATUS_CACHE_SECONDS : self::REMOTE_STATUS_FAILURE_CACHE_SECONDS;
            $this->upstreamRuntimeCache[$cacheKey] = $tuple;
            Cache::put($cacheKey, $tuple, $ttl);
        }

        return [$tuple[0], $tuple[1], $tuple[2]];
    }

    /**
     * Resolve the upstream branch and head SHA, cached like origin checks: the
     * network call is one `ls-remote` (with `--symref HEAD` when no branch is
     * configured, answering branch and head together), never a fetch.
     *
     * @param  array{remote: string, branch: string|null, repo: string|null, url: string}  $identity
     * @return array{0: string|null, 1: array<string, mixed>|null, 2: string|null, 3: string|null} [branch, head commit, error, detail]
     */
    private function upstreamHead(string $path, array $identity, bool $useRemoteCache): array
    {
        $cacheKey = 'software.source.upstream.'.hash('sha256', strtolower($identity['url']).'|'.($identity['branch'] ?? ''));
        $cached = $useRemoteCache
            ? ($this->upstreamRuntimeCache[$cacheKey] ?? Cache::get($cacheKey))
            : null;

        if (is_array($cached)) {
            [$branch, $sha, $error, $detail] = $cached;

            return [$branch, $sha !== null ? $this->gitReader->localObjectCommit($path, $sha) : null, $error, $detail];
        }

        $owner = $this->gitReader->upstreamOwner($identity['repo']);
        $repo = new GitRepository($path, $owner !== null ? $this->tokenFor($owner) : null);
        $label = $identity['repo'] ?? $identity['url'];

        if ($identity['branch'] !== null) {
            $branch = $identity['branch'];
            [$head, $error, $detail] = $this->gitReader->parseUpstreamHeadResult($path, $label, $branch, $repo->lsRemoteHead($branch, $identity['remote']));
            $sha = $head['sha'] ?? null;
        } else {
            $default = $repo->lsRemoteDefaultBranch($identity['remote']);
            $branch = $default['branch'] ?? null;
            $sha = $default['sha'] ?? null;
            $head = $sha !== null ? $this->gitReader->localObjectCommit($path, $sha) : null;
            $error = $default === null ? (string) __('Could not read the upstream head for :label.', ['label' => $label]) : null;
            $detail = null;
        }

        if ($useRemoteCache) {
            $tuple = [$branch, $sha, $error, $detail];
            $ttl = $sha !== null ? self::REMOTE_STATUS_CACHE_SECONDS : self::REMOTE_STATUS_FAILURE_CACHE_SECONDS;

            $this->upstreamRuntimeCache[$cacheKey] = $tuple;
            Cache::put($cacheKey, $tuple, $ttl);
        }

        return [$branch, $head, $error, $detail];
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

            $visibility = match (true) {
                $response->successful() && $response->json('private') === false => 'public',
                // Anonymous access to a private (or nonexistent) repo is a 404 —
                // GitHub does not distinguish the two to an unauthenticated caller.
                $response->status() === 404 => 'private',
                // Anything else (403 rate-limited, 5xx, ...) is a request failure,
                // not a fact about the repo's visibility.
                default => 'unknown',
            };
        } catch (ConnectionException) {
            $visibility = 'unknown';
        }

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
