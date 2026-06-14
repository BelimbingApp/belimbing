<?php

namespace App\Base\Update\Services;

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Discovers git-backed Distribution Bundles and reads their local/remote state.
 */
class DistributionBundleRepository
{
    private const GIT_DIRECTORY = '/.git';

    private const TOKEN_PREFIX = 'integrations.github.token.';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function status(): array
    {
        return array_map(function (array $dist): array {
            [$owner, $name] = $this->remoteIdentity($dist['path']);
            $branch = $this->git($dist['path'], ['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'main';

            $entry = [
                'key' => $dist['key'],
                'label' => $dist['label'],
                'path' => $dist['relative'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $branch,
                'current' => $this->localCommit($dist['path']),
                'latest' => null,
                'up_to_date' => null,
                'error' => null,
            ];

            if ($owner === null) {
                $entry['error'] = (string) __('No GitHub origin remote.');

                return $entry;
            }

            [$latest, $error] = $this->latestCommit($dist['path'], $owner, $name, $branch);

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
        $args = ['git'];

        if ($token !== null) {
            $args[] = '-c';
            $args[] = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$token);
        }

        $result = Process::path($dist['path'])
            ->timeout(180)
            ->run(array_merge($args, ['pull', '--ff-only']));

        if ($result->successful()) {
            return trim($result->output()) ?: (string) __('Already up to date.');
        }

        return (string) __('FAILED: :error', ['error' => trim($result->errorOutput() ?: $result->output())]);
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
        $found = [[
            'key' => 'platform',
            'label' => (string) __('Belimbing (platform)'),
            'path' => base_path(),
            'relative' => '.',
        ]];

        foreach (glob(base_path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir($dir.self::GIT_DIRECTORY)) {
                $found[] = $this->distribution($dir, (string) __('Module: :name', ['name' => basename($dir)]));
            }
        }

        foreach (glob(base_path('extensions/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir($dir.self::GIT_DIRECTORY)) {
                $found[] = $this->distribution($dir, (string) __('Extension: :name', ['name' => basename($dir)]));

                continue;
            }

            foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
                if (is_dir($sub.self::GIT_DIRECTORY)) {
                    $found[] = $this->distribution($sub, (string) __('Extension: :name', ['name' => basename($dir).'/'.basename($sub)]));
                }
            }
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

    private function localCommit(string $path): ?array
    {
        $line = $this->git($path, ['log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s']);

        if ($line === null || $line === '') {
            return null;
        }

        [$sha, $date, $author, $subject] = array_pad(explode("\x1f", $line), 4, '');

        return $this->commit($sha, $date, $author, $subject);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function latestCommit(string $path, string $owner, string $name, string $branch): array
    {
        $repo = "{$owner}/{$name}";
        $result = $this->lsRemote($path, $branch, $this->tokenFor($owner));

        if (! $result->successful()) {
            return [null, $this->remoteCommitError($repo, $branch, $result)];
        }

        $line = trim($result->output());
        $sha = (string) strtok($line, " \t");

        if ($sha === '' || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1) {
            return [null, (string) __('Git remote response for :repo@:branch did not include a commit SHA.', ['repo' => $repo, 'branch' => $branch])];
        }

        return [$this->remoteCommit($path, $sha), null];
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

    private function remoteCommitError(string $repo, string $branch, ProcessResult $result): string
    {
        $detail = $this->processError($result);

        return (string) __('Could not read latest commit for :repo@:branch via git ls-remote (:detail). Public repositories do not need a token; check the repo name, branch, or network access. If this repo is private, add a token in GitHub Access.', [
            'repo' => $repo,
            'branch' => $branch,
            'detail' => $detail,
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
     * @return array{0: string|null, 1: string|null}
     */
    private function remoteIdentity(string $path): array
    {
        if (! is_dir($path.self::GIT_DIRECTORY)) {
            return [null, null];
        }

        $url = $this->git($path, ['remote', 'get-url', 'origin']);

        if ($url !== null && preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [null, null];
    }

    private function lsRemote(string $path, string $branch, ?string $token): ProcessResult
    {
        $args = ['git'];

        if ($token !== null) {
            $args[] = '-c';
            $args[] = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$token);
        }

        return Process::path($path)
            ->timeout(30)
            ->run(array_merge($args, ['ls-remote', '--exit-code', 'origin', 'refs/heads/'.$branch]));
    }

    private function processError(ProcessResult $result): string
    {
        return trim($result->errorOutput() ?: $result->output())
            ?: (string) __('process exited with code :code', ['code' => $result->exitCode()]);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $path, array $args): ?string
    {
        $result = Process::path($path)->run(array_merge(['git'], $args));

        return $result->successful() ? trim($result->output()) : null;
    }
}
