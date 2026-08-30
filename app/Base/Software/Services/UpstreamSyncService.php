<?php

namespace App\Base\Software\Services;

use App\Base\Support\Git\GitRepository;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The two gated upstream-synchronization actions of #339 lane 3: refresh the
 * mirror (`origin/<branch>` fast-forwarded from `upstream/<branch>`) and cut a
 * release candidate (`rc` = `origin/master` + the mirror, pushed for a human
 * to open the UAT pull request in GitHub's UI).
 *
 * Both are plain git against remotes the machine already authenticates to —
 * no stored write credential exists here, deliberately (#339). Every entry
 * point calls UpstreamSyncGate::authorize() first: there is no path to a
 * write that does not pass the gate.
 *
 * The RC integration uses `git merge-tree --write-tree` + `commit-tree`, which
 * merge entirely in the object database: the active checkout is untouched by
 * construction, so a conflicting integration has nothing to abort or clean up
 * — it simply reports what conflicted (git >= 2.38).
 *
 * Refuse rather than force, throughout: a mirror that cannot fast-forward
 * means someone committed to it (a condition for a human); an existing `rc`
 * means an unfinished round (per-cycle policy, owner ruling on #339).
 */
final class UpstreamSyncService
{
    private const EXIT_CODE_ARG = '--exit-code';

    private const REF_HEADS_PREFIX = 'refs/heads/';

    public const RC_BRANCH = 'rc';

    public const STABLE_BRANCH = 'master';

    public function __construct(
        private readonly UpstreamSyncGate $gate,
        private readonly SoftwareSourceGitReader $gitReader,
    ) {}

    /**
     * Fast-forward the mirror branch on origin from the upstream remote,
     * creating it when absent.
     *
     * @return array{ok: bool, message: string, detail: string|null}
     */
    public function refreshMirror(?Authenticatable $user): array
    {
        $this->gate->authorize($user);

        $path = base_path();
        $identity = $this->gitReader->upstreamIdentity($path);

        if ($identity === null) {
            return $this->failure((string) __('This checkout has no upstream remote, so there is no mirror to refresh.'));
        }

        $repo = $this->syncRepository($path);
        $upstream = $this->resolveUpstreamHead($repo, $identity);

        if (! is_array($upstream)) {
            return $this->failure((string) __('Could not read the upstream head.'), $upstream);
        }

        [$branch, $upstreamSha] = $upstream;

        // The upstream objects must be local before ancestry can be judged or
        // anything pushed. A fetch writes only to this clone's object store.
        $fetched = $repo->run(['fetch', $identity['remote'], $branch], timeout: 300);

        if (! $fetched->ok) {
            return $this->failure((string) __('Could not fetch :remote/:branch.', ['remote' => $identity['remote'], 'branch' => $branch]), $fetched->message());
        }

        // ls-remote --exit-code separates "no such ref" (2) from a failed lookup
        // (128, auth/network). A failure is not a fact about the repository
        // (sol's P1 on #356): refusing here stops an unreachable origin from
        // being read as "mirror absent" and answered with a creation push.
        $mirror = $repo->run(['ls-remote', self::EXIT_CODE_ARG, 'origin', self::REF_HEADS_PREFIX.$branch]);

        if (! $mirror->ok && $mirror->exitCode !== 2) {
            return $this->failure((string) __('Could not determine whether origin/:branch exists.', ['branch' => $branch]), $mirror->message());
        }

        if ($mirror->ok) {
            $mirrorSha = (string) strtok($mirror->output, " \t");

            if ($mirrorSha === $upstreamSha) {
                return ['ok' => true, 'message' => (string) __('The mirror is already current: origin/:branch equals :remote/:branch at :sha.', ['branch' => $branch, 'remote' => $identity['remote'], 'sha' => substr($upstreamSha, 0, 7)]), 'detail' => null];
            }

            // Anything on the mirror that upstream does not contain means a human
            // committed to it. That breaks the mirror's whole guarantee — refuse
            // and name the condition; never force-push over their work.
            if (! $repo->run(['merge-base', '--is-ancestor', $mirrorSha, $upstreamSha])->ok) {
                return $this->failure((string) __('Refused: origin/:branch has commits that :remote/:branch does not contain — someone has committed to the mirror directly. The mirror must only ever fast-forward; reconcile it manually.', ['branch' => $branch, 'remote' => $identity['remote']]));
            }
        }

        // Plain push — never --force. Git itself refuses a non-fast-forward, so
        // even a mirror moved between our check and the push stays protected.
        $pushed = $repo->run(['push', 'origin', $upstreamSha.':'.self::REF_HEADS_PREFIX.$branch], timeout: 300);

        if (! $pushed->ok) {
            return $this->failure((string) __('Could not push the mirror to origin/:branch.', ['branch' => $branch]), $pushed->message());
        }

        return [
            'ok' => true,
            'message' => $mirror->ok
                ? (string) __('Mirror refreshed: origin/:branch fast-forwarded to :sha.', ['branch' => $branch, 'sha' => substr($upstreamSha, 0, 7)])
                : (string) __('Mirror created: origin/:branch now tracks :remote/:branch at :sha.', ['branch' => $branch, 'remote' => $identity['remote'], 'sha' => substr($upstreamSha, 0, 7)]),
            'detail' => null,
        ];
    }

    /**
     * Cut the release candidate: `rc` from `origin/master` with the mirror
     * merged in, entirely in the object database, pushed for a human to open
     * the PR. Stops there by design.
     *
     * @return array{ok: bool, message: string, detail: string|null}
     */
    public function cutReleaseCandidate(?Authenticatable $user): array
    {
        $this->gate->authorize($user);

        $path = base_path();
        $identity = $this->gitReader->upstreamIdentity($path);

        if ($identity === null) {
            return $this->failure((string) __('This checkout has no upstream remote, so there is no mirror to integrate.'));
        }

        $repo = $this->syncRepository($path);

        // Per-cycle rc (owner ruling on #339): an existing rc is an unfinished
        // round. Refuse with the state named rather than force or reuse — and a
        // failed lookup (exit 128) is not "absent" (exit 2): cutting while
        // GitHub is unreachable must refuse, not proceed (sol's P1 on #356).
        $rcFailure = $this->ensureReleaseCandidateBranchIsAbsent($repo);

        if ($rcFailure !== null) {
            return $rcFailure;
        }

        $upstream = $this->resolveUpstreamHead($repo, $identity);

        if (! is_array($upstream)) {
            return $this->failure((string) __('Could not read the upstream head.'), $upstream);
        }

        [$branch] = $upstream;

        $fetchFailure = $this->fetchReleaseCandidateInputs($repo, $branch);

        if ($fetchFailure !== null) {
            return $fetchFailure;
        }

        $shas = $this->resolveReleaseCandidateInputs($repo, $branch);

        if (! array_is_list($shas)) {
            return $shas;
        }

        [$stableSha, $mirrorSha] = $shas;

        $tree = $this->releaseCandidateTree($repo, $stableSha, $mirrorSha);

        if (! is_string($tree)) {
            return $tree;
        }

        $commit = $repo->run([
            'commit-tree', $tree,
            '-p', $stableSha,
            '-p', $mirrorSha,
            '-m', sprintf('rc: integrate %s/%s into %s', $identity['remote'], $branch, self::STABLE_BRANCH),
        ]);

        if (! $commit->ok) {
            return $this->failure((string) __('Could not create the release-candidate commit.'), $commit->message());
        }

        $pushFailure = $this->pushReleaseCandidate($repo, $commit->output);

        if ($pushFailure !== null) {
            return $pushFailure;
        }

        return [
            'ok' => true,
            'message' => (string) __('Release candidate pushed as origin/rc (:sha). Open the pull request rc → :stable in GitHub to start UAT.', ['sha' => substr($commit->output, 0, 7), 'stable' => self::STABLE_BRANCH]),
            'detail' => null,
        ];
    }

    /**
     * @param  array{remote: string, branch: string|null, repo: string|null, url: string}  $identity
     * @return array{0: string, 1: string}|string|null [branch, sha], or an error detail
     */
    private function resolveUpstreamHead(GitRepository $repo, array $identity): array|string|null
    {
        if ($identity['branch'] !== null) {
            $result = $repo->run(['ls-remote', self::EXIT_CODE_ARG, $identity['remote'], self::REF_HEADS_PREFIX.$identity['branch']]);

            if (! $result->ok) {
                return $result->message();
            }

            $sha = (string) strtok($result->output, " \t");

            return preg_match('/^[a-f0-9]{40}$/i', $sha) === 1 ? [$identity['branch'], $sha] : $result->output;
        }

        $default = $repo->lsRemoteDefaultBranch($identity['remote']);

        return $default !== null ? [$default['branch'], $default['sha']] : null;
    }

    /**
     * @return array{ok: bool, message: string, detail: string|null}|null
     */
    private function ensureReleaseCandidateBranchIsAbsent(GitRepository $repo): ?array
    {
        $rc = $repo->run(['ls-remote', self::EXIT_CODE_ARG, 'origin', self::REF_HEADS_PREFIX.self::RC_BRANCH]);

        if ($rc->ok) {
            return $this->failure((string) __('Refused: an rc branch already exists on origin from a previous round. Merge or delete it before cutting a new release candidate.'));
        }

        if ($rc->exitCode !== 2) {
            return $this->failure((string) __('Could not determine whether an rc branch already exists on origin.'), $rc->message());
        }

        return null;
    }

    /**
     * @return array{ok: bool, message: string, detail: string|null}|null
     */
    private function fetchReleaseCandidateInputs(GitRepository $repo, string $branch): ?array
    {
        $fetched = $repo->run(['fetch', 'origin', self::STABLE_BRANCH, $branch], timeout: 300);

        if ($fetched->ok) {
            return null;
        }

        return $this->failure((string) __('Could not fetch origin/:stable and origin/:branch.', ['stable' => self::STABLE_BRANCH, 'branch' => $branch]), $fetched->message());
    }

    /**
     * @return array{0: string, 1: string}|array{ok: bool, message: string, detail: string|null}
     */
    private function resolveReleaseCandidateInputs(GitRepository $repo, string $branch): array
    {
        $stableSha = $repo->output(['rev-parse', 'refs/remotes/origin/'.self::STABLE_BRANCH]);
        $mirrorSha = $repo->output(['rev-parse', 'refs/remotes/origin/'.$branch]);

        if ($stableSha === null || $mirrorSha === null) {
            return $this->failure((string) __('Could not resolve origin/:stable and origin/:branch after fetching. Refresh the mirror first.', ['stable' => self::STABLE_BRANCH, 'branch' => $branch]));
        }

        return [$stableSha, $mirrorSha];
    }

    /**
     * @return string|array{ok: bool, message: string, detail: string|null}
     */
    private function releaseCandidateTree(GitRepository $repo, string $stableSha, string $mirrorSha): string|array
    {
        // A real merge, written only to the object database: the active checkout
        // is untouched whether this succeeds or conflicts.
        $merge = $repo->run(['merge-tree', '--write-tree', '--name-only', $stableSha, $mirrorSha]);

        // merge-tree exits 1 for a conflicted merge (first output line is still a
        // tree oid, the rest are the conflicted paths) and >1 for a real error.
        if (! $merge->ok && $merge->exitCode === 1) {
            $conflicted = implode(', ', array_slice($this->releaseCandidateConflictFiles($merge->output), 0, 20));

            return $this->failure(
                (string) __('The integration conflicts; the release candidate was not created and the working tree was not touched. A person needs to resolve these files: :files', ['files' => $conflicted !== '' ? $conflicted : (string) __('(none listed)')]),
                $merge->output,
            );
        }

        if (! $merge->ok) {
            return $this->failure((string) __('Could not compute the integration merge.'), $merge->message());
        }

        return (string) strtok($merge->output, "\n");
    }

    /**
     * @return list<string>
     */
    private function releaseCandidateConflictFiles(string $output): array
    {
        // Observed output shape (git 2.54): tree oid, the conflicted paths,
        // then a blank line followed by informational messages. Only the
        // section before the blank line is file names.
        $lines = preg_split('/\R/', $output) ?: [];
        $files = [];

        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                break;
            }

            $files[] = trim($line);
        }

        return $files;
    }

    /**
     * @return array{ok: bool, message: string, detail: string|null}|null
     */
    private function pushReleaseCandidate(GitRepository $repo, string $commitSha): ?array
    {
        // The preflight absence check cannot be atomic with the push, so the push
        // itself carries the expectation: --force-with-lease with an empty value
        // is git's documented "must not exist" form. Measured (git 2.54): creates
        // the branch when absent, rejects with "(stale info)" when any rc appeared
        // since — including a descendant, which a plain push would fast-forward,
        // letting two concurrent cuts both report success (sol's P1 on #356).
        $pushed = $repo->run(['push', 'origin', $commitSha.':'.self::REF_HEADS_PREFIX.self::RC_BRANCH, '--force-with-lease='.self::REF_HEADS_PREFIX.self::RC_BRANCH.':'], timeout: 300);

        if (! $pushed->ok && str_contains($pushed->message(), 'stale info')) {
            return $this->failure((string) __('Refused: an rc branch appeared on origin while this cut was being prepared. Check whether another operator just cut one.'), $pushed->message());
        }

        if (! $pushed->ok) {
            return $this->failure((string) __('Could not push rc to origin.'), $pushed->message());
        }

        return null;
    }

    /**
     * Sync runs on the machine's own git credential helper — the whole design
     * stores no write credential (#339). Reads and pushes alike stay
     * non-interactive via GIT_TERMINAL_PROMPT=0.
     */
    private function syncRepository(string $path): GitRepository
    {
        return new GitRepository($path, ambientCredentials: true);
    }

    /**
     * @return array{ok: bool, message: string, detail: string|null}
     */
    private function failure(string $message, ?string $detail = null): array
    {
        return ['ok' => false, 'message' => $message, 'detail' => $detail];
    }
}
