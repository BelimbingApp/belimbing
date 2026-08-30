<?php

namespace App\Base\Software\Services;

use App\Base\Support\Git\GitRepository;
use App\Base\Support\Git\GitResult;
use Illuminate\Support\Carbon;

final class SoftwareSourceGitReader
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
     * How far the local checkout at $path is ahead of / behind an arbitrary
     * remote SHA — unlike workingTree(), not limited to the (possibly stale)
     * upstream tracking ref. Null when that SHA's object isn't present locally.
     *
     * @return array{ahead: int, behind: int}|null
     */
    public function liveAheadBehind(string $path, string $sha, int $timeout = 30): ?array
    {
        return (new GitRepository($path))->aheadBehindFrom($sha, timeout: $timeout);
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
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: string|null} [commit, operator summary, raw git detail]
     */
    public function parseLatestCommitResult(string $path, string $owner, string $name, string $branch, GitResult $result): array
    {
        $repo = "{$owner}/{$name}";

        if (! $result->ok) {
            [$summary, $detail] = $this->remoteCommitFailure($owner, $repo, $branch, $result);

            return [null, $summary, $detail];
        }

        $sha = (string) strtok($result->output, " \t");

        if ($sha === '' || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1) {
            return [null, (string) __('Git remote response for :repo@:branch did not include a commit SHA.', ['repo' => $repo, 'branch' => $branch]), $result->output];
        }

        return [$this->remoteCommit($path, $sha), null, null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{sha: string, short: string, date: string|null, ago: string|null, author: string, subject: string}|null
     */
    public function githubCommit(string $expectedSha, array $payload): ?array
    {
        $sha = $payload['sha'] ?? null;
        $commit = $payload['commit'] ?? null;

        if (! is_string($sha) || strcasecmp($sha, $expectedSha) !== 0 || ! is_array($commit)) {
            return null;
        }

        $committer = is_array($commit['committer'] ?? null) ? $commit['committer'] : [];
        $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
        $date = $committer['date'] ?? $author['date'] ?? null;

        if (! is_string($date) || $date === '') {
            return null;
        }

        $authorName = $author['name'] ?? $committer['name'] ?? '';
        $message = is_string($commit['message'] ?? null) ? $commit['message'] : '';

        return $this->commit(
            $sha,
            $date,
            is_string($authorName) ? $authorName : '',
            rtrim(explode("\n", $message, 2)[0], "\r"),
        );
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
     * The platform checkout's framework-upstream remote, when one exists.
     *
     * Discovery order: the checkout's own `belimbing.upstream-remote` git config
     * (an operator statement, so it wins), then a remote literally named
     * `upstream` (the fork convention GitHub itself creates). The branch comes
     * from `belimbing.upstream-branch` when set; otherwise it is resolved later
     * from the remote's HEAD symref, so a non-default branch never has to be
     * guessed. Null — not an error — when the checkout has no upstream remote:
     * a non-fork deployment must render exactly as it does today.
     *
     * @return array{remote: string, branch: string|null, repo: string|null, url: string}|null
     */
    public function upstreamIdentity(string $path, int $timeout = 30): ?array
    {
        $repo = new GitRepository($path);
        $remotes = $repo->remotes(timeout: $timeout);

        if ($remotes === []) {
            return null;
        }

        $configured = $repo->configValue('belimbing.upstream-remote', timeout: $timeout);
        if ($configured !== null && in_array($configured, $remotes, true)) {
            $remote = $configured;
        } elseif (in_array('upstream', $remotes, true)) {
            $remote = 'upstream';
        } else {
            $remote = null;
        }

        if ($remote === null) {
            return null;
        }

        $url = $repo->remoteUrl($remote, timeout: $timeout) ?? $repo->configuredRemoteUrl($remote);

        if ($url === null) {
            return null;
        }

        $identity = $this->githubRemoteIdentity($url);

        return [
            'remote' => $remote,
            'branch' => $repo->configValue('belimbing.upstream-branch', timeout: $timeout),
            'repo' => $identity !== null ? $identity[0].'/'.$identity[1] : null,
            'url' => $url,
        ];
    }

    /**
     * Owner half of a GitHub upstream identity, for token lookup only.
     */
    public function upstreamOwner(?string $repo): ?string
    {
        return $repo !== null ? explode('/', $repo, 2)[0] : null;
    }

    /**
     * Commit metadata for a SHA whose object may already be local; falls back to
     * a metadata-less entry naming only the SHA when it is not.
     *
     * @return array{sha: string, short: string, date: string|null, ago: string|null, author: string, subject: string}
     */
    public function localObjectCommit(string $path, string $sha): array
    {
        return $this->remoteCommit($path, $sha);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: string|null} [commit, operator summary, raw git detail]
     */
    public function parseUpstreamHeadResult(string $path, string $label, string $branch, GitResult $result): array
    {
        if (! $result->ok) {
            $detail = $result->message();

            if ($this->isAuthFailure($detail)) {
                return [null, (string) __('Upstream :label needs credentials to read.', ['label' => $label]), $detail];
            }

            return [null, (string) __('Could not read the upstream head for :label@:branch.', ['label' => $label, 'branch' => $branch]), $detail];
        }

        $sha = (string) strtok($result->output, " \t");

        if ($sha === '' || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1) {
            return [null, (string) __('Upstream response for :label@:branch did not include a commit SHA.', ['label' => $label, 'branch' => $branch]), $result->output];
        }

        return [$this->remoteCommit($path, $sha), null, null];
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

    /**
     * Git's own words, matched case-insensitively. Every one of these means the
     * same thing to an operator — the remote wanted credentials and did not get
     * usable ones — and none of them is helped by being told that public
     * repositories do not need a token.
     */
    private const AUTH_FAILURE_SIGNATURES = [
        'could not read username',
        'could not read password',
        'authentication failed',
        'terminal prompts disabled',
        'invalid username or token',
        'http basic: access denied',
        'permission denied (publickey',
        // git surfaces a bare HTTP status for a token that exists but cannot see
        // the repo: `fatal: unable to access '...': The requested URL returned
        // error: 403`. Matched narrowly so an unrelated 403 in a URL or a repo
        // name cannot be read as an auth failure.
        'returned error: 403',
        'error 403',
    ];

    /**
     * Whether a git failure means "the remote wanted credentials".
     *
     * Public because the page banner has to make the same call the table cell
     * does: it used to lead every failure with "public repositories do not need
     * a token", which is the opposite of the truth for exactly these cases.
     */
    public function isAuthFailure(?string $detail): bool
    {
        if ($detail === null || $detail === '') {
            return false;
        }

        $haystack = strtolower($detail);

        foreach (self::AUTH_FAILURE_SIGNATURES as $signature) {
            if (str_contains($haystack, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a failed remote read into one actionable line and the raw git output.
     *
     * The single generic message this replaced opened with "Public repositories do
     * not need a token" for *every* failure, including `could not read Username for
     * 'https://github.com'` — which says the opposite. A deployed install showed
     * that text, three lines wide, in a table cell, with the real cause last.
     *
     * @return array{0: string, 1: string} [operator summary, raw git detail]
     */
    private function remoteCommitFailure(string $owner, string $repo, string $branch, GitResult $result): array
    {
        $detail = $result->message();

        if ($this->isAuthFailure($detail)) {
            return [
                (string) __(':repo needs credentials — add a token for :owner in GitHub Access.', [
                    'repo' => $repo,
                    'owner' => $owner,
                ]),
                $detail,
            ];
        }

        return [
            (string) __('Could not read latest commit for :repo@:branch. Public repositories do not need a token; check the repo name, branch, or network access.', [
                'repo' => $repo,
                'branch' => $branch,
            ]),
            $detail,
        ];
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
