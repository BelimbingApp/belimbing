<?php

namespace App\Base\Software\Services;

use App\Base\Support\Git\GitRepository;
use App\Base\Support\Git\GitResult;
use Illuminate\Support\Carbon;

final class DistributionBundleGitReader
{
    /**
     * @return array{branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null}
     */
    public function localSnapshot(string $path, bool $readWorkingTree = true, int $timeout = 60): array
    {
        $repo = new GitRepository($path);

        $summary = $readWorkingTree ? $repo->statusSummary(timeout: $timeout) : null;

        return [
            'branch' => $summary['branch'] ?? $repo->currentBranch(timeout: $timeout) ?? 'main',
            'working_tree' => $readWorkingTree ? $this->workingTree($path, timeout: $timeout, summary: $summary) : $this->cleanWorkingTree(),
            'current' => $this->localCommit($path, timeout: $timeout),
        ];
    }

    /**
     * @param  array{branch: string|null, dirty: int, ahead: int, behind: int}|null  $summary
     * @return array{dirty: int, ahead: int, behind: int}
     */
    public function workingTree(string $path, int $timeout = 60, ?array $summary = null): array
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
     * @return array{sha: string, short: string, date: string|null, ago: string|null, author: string, subject: string}|null
     */
    public function localCommit(string $path, int $timeout = 60): ?array
    {
        $line = $this->output($path, ['log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'], timeout: $timeout);

        if ($line === null || $line === '') {
            return null;
        }

        [$sha, $date, $author, $subject] = array_pad(explode("\x1f", $line), 4, '');

        return $this->commit($sha, $date, $author, $subject);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function latestCommit(string $path, string $owner, string $name, string $branch, ?string $token): array
    {
        $repo = "{$owner}/{$name}";
        $result = (new GitRepository($path, $token))->lsRemoteHead($branch);

        if (! $result->ok) {
            return [null, $this->remoteCommitError($repo, $branch, $result)];
        }

        $line = $result->output;
        $sha = (string) strtok($line, " \t");

        if ($sha === '' || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1) {
            return [null, (string) __('Git remote response for :repo@:branch did not include a commit SHA.', ['repo' => $repo, 'branch' => $branch])];
        }

        return [$this->remoteCommit($path, $sha), null];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function parseLatestCommitResult(string $path, string $owner, string $name, string $branch, GitResult $result): array
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
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    public function remoteIdentity(string $path, int $timeout = 60): array
    {
        $repo = new GitRepository($path);
        $remote = $repo->run(['remote', 'get-url', 'origin'], timeout: $timeout);

        if ($remote->ok) {
            $remoteUrl = $remote->output;
        } else {
            $remoteUrl = $repo->configuredRemoteUrl('origin');

            if ($remoteUrl === null) {
                return [null, null, (string) __('Could not read Git origin remote for :path: :detail', [
                    'path' => $path,
                    'detail' => $remote->message(),
                ])];
            }
        }

        return $this->githubRemoteIdentity($remoteUrl)
            ?? [null, null, (string) __('Git origin remote is not a GitHub repository: :remote', ['remote' => $remoteUrl])];
    }

    /**
     * @param  list<string>  $args
     */
    public function output(string $path, array $args, int $timeout = 60): ?string
    {
        return (new GitRepository($path))->output($args, timeout: $timeout);
    }

    public function isRepositoryPath(string $path): bool
    {
        return (new GitRepository($path))->isRepository();
    }

    /**
     * @return array{0: string, 1: string, 2: null}|null
     */
    private function githubRemoteIdentity(string $remote): ?array
    {
        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remote, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2], null];
    }

    /**
     * @return array{dirty: int, ahead: int, behind: int}
     */
    private function cleanWorkingTree(): array
    {
        return ['dirty' => 0, 'ahead' => 0, 'behind' => 0];
    }

    /**
     * @return array{sha: string, short: string, date: string|null, ago: string|null, author: string, subject: string}
     */
    private function remoteCommit(string $path, string $sha): array
    {
        $line = $this->output($path, ['show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', $sha]);

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
}
