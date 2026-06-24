<?php

namespace App\Base\Software\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Git\GitRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Discovers git-backed Distribution Bundles and reads their local/remote state.
 */
class DistributionBundleRepository
{
    private const TOKEN_PREFIX = 'integrations.github.token.';

    private readonly DistributionBundleGitReader $gitReader;

    public function __construct(private readonly SettingsService $settings, ?DistributionBundleGitReader $gitReader = null)
    {
        $this->gitReader = $gitReader ?? new DistributionBundleGitReader;
    }

    /**
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function status(): array
    {
        return array_map(function (array $dist): array {
            [$owner, $name, $remoteError] = $this->gitReader->remoteIdentity($dist['path']);
            $branch = $this->gitReader->output($dist['path'], ['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'main';

            $entry = [
                'key' => $dist['key'],
                'label' => $dist['label'],
                'path' => $dist['relative'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $branch,
                'working_tree' => $this->gitReader->workingTree($dist['path']),
                'current' => $this->gitReader->localCommit($dist['path']),
                'latest' => null,
                'up_to_date' => null,
                'error' => null,
            ];

            if ($owner === null) {
                $entry['error'] = $remoteError ?? (string) __('No GitHub origin remote.');

                return $entry;
            }

            [$latest, $error] = $this->gitReader->latestCommit($dist['path'], $owner, $name, $branch, $this->tokenFor($owner));

            if ($latest === null) {
                $entry['error'] = $error;

                return $entry;
            }

            $entry['latest'] = $latest;
            $entry['up_to_date'] = $entry['current'] !== null && $entry['current']['sha'] === $latest['sha'];

            return $entry;
        }, $this->distributions());
    }

    /**
     * Per-bundle LOCAL state only — no network calls, unlike status(). The Software
     * Inventory read model reports what is really on disk (branch, working tree,
     * current commit) without paying a GitHub round-trip per bundle on every render.
     *
     * @return list<array{key: string, label: string, path: string, absolutePath: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null}>
     */
    public function localStatus(): array
    {
        return array_map(function (array $dist): array {
            $isRepo = $this->gitReader->isRepositoryPath($dist['path']);
            [$owner, $name] = $this->gitReader->remoteIdentity($dist['path']);

            return [
                'key' => $dist['key'],
                'label' => $dist['label'],
                'path' => $dist['relative'],
                'absolutePath' => $dist['path'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $isRepo ? ($this->gitReader->output($dist['path'], ['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'main') : null,
                'working_tree' => $isRepo ? $this->gitReader->workingTree($dist['path']) : ['dirty' => 0, 'ahead' => 0, 'behind' => 0],
                'current' => $this->gitReader->localCommit($dist['path']),
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
            [$owner, $name] = $this->gitReader->remoteIdentity($dist['path']);

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
            [$repoOwner, $name] = $this->gitReader->remoteIdentity($dist['path']);

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
        [$owner] = $this->gitReader->remoteIdentity($dist['path']);
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
        $status = collect($this->status())->keyBy('key');
        $lines = [];

        foreach ($targets as $target) {
            $entry = $status->get($target['key']);

            if (! is_array($entry)) {
                $lines[] = (string) __('Could not verify :label after update; refresh the page to check its current status.', ['label' => $target['label']]);

                continue;
            }

            if ($entry['up_to_date'] === true) {
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

        return $lines === []
            ? [(string) __('Verified: selected Distribution Bundles are up to date.')]
            : $lines;
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
            if ($this->gitReader->isRepositoryPath($dir)) {
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

            if ($this->gitReader->isRepositoryPath($dir)) {
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
}
