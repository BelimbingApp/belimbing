<?php

namespace App\Base\Support\Git;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Thin, testable wrapper around the git CLI scoped to one working-tree path.
 *
 * Bundles live in their own nested git repos, so several subsystems (the deploy
 * console, schema incubation) must run git inside a specific checkout. This hides
 * the Process plumbing and the HTTPS token header behind verbs, and stays a deep
 * module: only the operations we actually use. It shells out through the Process
 * facade, so `Process::fake()` drives it in tests with no extra seams.
 */
final class GitRepository
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $token = null,
        private readonly ?string $executable = null,
    ) {}

    public function isRepository(): bool
    {
        return file_exists($this->path.DIRECTORY_SEPARATOR.'.git');
    }

    public function currentBranch(int $timeout = 60): ?string
    {
        return $this->output(['rev-parse', '--abbrev-ref', 'HEAD'], timeout: $timeout);
    }

    public function remoteUrl(string $remote = 'origin', int $timeout = 60): ?string
    {
        return $this->output(['remote', 'get-url', $remote], timeout: $timeout);
    }

    public function configuredRemoteUrl(string $remote = 'origin'): ?string
    {
        return (new GitRepositoryConfigReader($this->path))->remoteUrl($remote);
    }

    /**
     * Branch, dirty count, and upstream divergence from one git process.
     *
     * `git status --porcelain=v1 --branch` is stable machine output and avoids
     * paying separately for `rev-parse`, `status --porcelain`, and `rev-list`.
     *
     * @return array{branch: string|null, dirty: int, ahead: int, behind: int}|null
     */
    public function statusSummary(int $timeout = 60): ?array
    {
        $status = $this->output(['status', '--porcelain=v1', '--branch'], timeout: $timeout);

        if ($status === null || $status === '') {
            return null;
        }

        $lines = preg_split('/\R/', $status) ?: [];
        $branchLine = array_shift($lines);

        if (! is_string($branchLine) || ! str_starts_with($branchLine, '## ')) {
            return null;
        }

        return [
            'branch' => $this->parseStatusBranch($branchLine),
            'dirty' => count(array_filter($lines, fn (string $line): bool => trim($line) !== '')),
            'ahead' => $this->parseStatusCount($branchLine, 'ahead'),
            'behind' => $this->parseStatusCount($branchLine, 'behind'),
        ];
    }

    /**
     * Number of uncommitted (staged + unstaged + untracked) entries.
     */
    public function uncommittedCount(int $timeout = 60): int
    {
        $porcelain = $this->output(['status', '--porcelain'], timeout: $timeout);

        return $porcelain === null || $porcelain === '' ? 0 : count(explode("\n", $porcelain));
    }

    /**
     * How far the current branch is ahead of / behind its upstream. Zero when
     * there is no upstream or the count can't be read.
     *
     * @return array{ahead: int, behind: int}
     */
    public function aheadBehind(int $timeout = 60): array
    {
        $counts = $this->output(['rev-list', '--left-right', '--count', '@{u}...HEAD'], timeout: $timeout);

        if ($counts !== null && preg_match('/^(\d+)\s+(\d+)$/', $counts, $matches) === 1) {
            return ['ahead' => (int) $matches[2], 'behind' => (int) $matches[1]];
        }

        return ['ahead' => 0, 'behind' => 0];
    }

    /**
     * Stage and commit exactly the given paths — never a blanket `add -A` — so an
     * action that rewrote specific files can't sweep unrelated working-tree changes
     * into the commit. Returns a skipped result when there is nothing to commit.
     *
     * @param  list<string>  $paths  absolute or repo-relative paths
     */
    public function commit(array $paths, string $message): GitResult
    {
        if ($paths === []) {
            return GitResult::skipped();
        }

        $staged = $this->run(array_merge(['add', '--'], $paths));

        if (! $staged->ok) {
            return $staged;
        }

        return $this->run(array_merge(['commit', '-m', $message, '--'], $paths));
    }

    public function pull(): GitResult
    {
        return $this->run(['pull', '--ff-only'], authenticated: true, timeout: 180);
    }

    public function lsRemoteHead(string $branch): GitResult
    {
        return $this->run(['ls-remote', '--exit-code', 'origin', 'refs/heads/'.$branch], authenticated: true, timeout: 30);
    }

    /**
     * Build a scoped git command for callers that need to run it through a pool.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function command(array $args, bool $authenticated = false): array
    {
        $explicitExecutable = $this->executable !== null && $this->executable !== '';
        $command = [
            $explicitExecutable ? (string) $this->executable : $this->configuredExecutable(),
            '-c',
            'safe.directory='.$this->safeDirectory(),
        ];

        if ($authenticated && $this->token !== null) {
            $command[] = '-c';
            $command[] = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$this->token);
        }

        return array_merge($command, $args);
    }

    /**
     * Run git and return trimmed stdout on success, or null on failure.
     *
     * @param  list<string>  $args
     */
    public function output(array $args, int $timeout = 60): ?string
    {
        $result = $this->run($args, timeout: $timeout);

        return $result->ok ? $result->output : null;
    }

    /**
     * @param  list<string>  $args
     */
    public function run(array $args, bool $authenticated = false, int $timeout = 60): GitResult
    {
        try {
            $result = Process::path($this->path)->timeout($timeout)->run($this->command($args, authenticated: $authenticated));
        } catch (Throwable $exception) {
            return new GitResult(
                ok: false,
                output: '',
                error: (string) __('Could not run git in :path: :message', [
                    'path' => $this->path,
                    'message' => $exception->getMessage(),
                ]),
                exitCode: -1,
                started: false,
            );
        }

        return new GitResult(
            ok: $result->successful(),
            output: trim($result->output()),
            error: trim($result->errorOutput()),
            exitCode: $result->exitCode() ?? -1,
        );
    }

    private function configuredExecutable(): string
    {
        $configured = function_exists('config') ? config('app.git_executable') : null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $configured = function_exists('env') ? env('BLB_GIT_EXECUTABLE') : null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $environmentExecutable = getenv('BLB_GIT_EXECUTABLE') ?: getenv('GIT_EXECUTABLE');

        return is_string($environmentExecutable) && $environmentExecutable !== ''
            ? $environmentExecutable
            : 'git';
    }

    private function safeDirectory(): string
    {
        $path = realpath($this->path) ?: $this->path;

        return str_replace('\\', '/', $path);
    }

    private function parseStatusBranch(string $branchLine): ?string
    {
        $summary = trim(substr($branchLine, 3));
        $summary = preg_replace('/\s+\[[^\]]+\]$/', '', $summary) ?? $summary;

        if (str_contains($summary, '...')) {
            $summary = explode('...', $summary, 2)[0];
        }

        if (preg_match('/^No commits yet on (.+)$/', $summary, $matches) === 1) {
            $summary = $matches[1];
        }

        return $summary !== '' ? $summary : null;
    }

    private function parseStatusCount(string $branchLine, string $name): int
    {
        return preg_match('/\b'.preg_quote($name, '/').'\s+(\d+)\b/', $branchLine, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }
}
