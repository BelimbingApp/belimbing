<?php

namespace App\Base\Support\Git;

use Illuminate\Support\Facades\Process;

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
    ) {}

    public function isRepository(): bool
    {
        return is_dir($this->path.DIRECTORY_SEPARATOR.'.git');
    }

    public function currentBranch(): ?string
    {
        return $this->output(['rev-parse', '--abbrev-ref', 'HEAD']);
    }

    public function remoteUrl(string $remote = 'origin'): ?string
    {
        return $this->output(['remote', 'get-url', $remote]);
    }

    /**
     * Number of uncommitted (staged + unstaged + untracked) entries.
     */
    public function uncommittedCount(): int
    {
        $porcelain = $this->output(['status', '--porcelain']);

        return $porcelain === null || $porcelain === '' ? 0 : count(explode("\n", $porcelain));
    }

    /**
     * How far the current branch is ahead of / behind its upstream. Zero when
     * there is no upstream or the count can't be read.
     *
     * @return array{ahead: int, behind: int}
     */
    public function aheadBehind(): array
    {
        $counts = $this->output(['rev-list', '--left-right', '--count', '@{u}...HEAD']);

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
     * Run git and return trimmed stdout on success, or null on failure.
     *
     * @param  list<string>  $args
     */
    public function output(array $args): ?string
    {
        $result = $this->run($args);

        return $result->ok ? $result->output : null;
    }

    /**
     * @param  list<string>  $args
     */
    public function run(array $args, bool $authenticated = false, int $timeout = 60): GitResult
    {
        $command = ['git'];

        if ($authenticated && $this->token !== null) {
            $command[] = '-c';
            $command[] = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$this->token);
        }

        $result = Process::path($this->path)->timeout($timeout)->run(array_merge($command, $args));

        return new GitResult(
            ok: $result->successful(),
            output: trim($result->output()),
            error: trim($result->errorOutput()),
            exitCode: $result->exitCode() ?? -1,
        );
    }
}
