<?php

namespace App\Base\Software\Services;

use App\Base\Support\Git\GitRepository;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The two gated upstream-synchronization actions of #339 lane 3: refresh the
 * mirror (`origin/<branch>` fast-forwarded from `upstream/<branch>`, kept as
 * optional compatibility information — #455) and prepare an upstream
 * integration proposal (`origin/master` merged with the pinned upstream head,
 * pushed for a human to open the UAT pull request in GitHub's UI).
 *
 * Both are plain git against remotes the machine already authenticates to —
 * no stored write credential exists here, deliberately (#339). Every entry
 * point calls UpstreamSyncGate::authorize() first: there is no path to a
 * write that does not pass the gate.
 *
 * The integration uses `git merge-tree --write-tree` + `commit-tree`, which
 * merge entirely in the object database: the active checkout is untouched by
 * construction, so a conflicting integration has nothing to abort or clean up
 * — it simply reports what conflicted (git >= 2.38).
 *
 * #455 dropped the mandatory `origin/<upstream-branch>` mirror from this
 * operation: the mirror was an observability choice (a permanent branch
 * naming which upstream commit was last vetted), never a dependency the
 * integration itself needed — `upstream/main` and `origin/master` are
 * sufficient on their own, and pinning the upstream head's exact SHA before
 * any ancestry or merge check is the safety property that actually mattered.
 * The proposal branch is named for that pinned SHA
 * (`upstream-sync-<short-sha>`), replacing the single per-cycle `rc`: each
 * candidate is independently identifiable, and a repeated attempt at the same
 * upstream SHA names the same branch rather than colliding with an unrelated
 * one.
 *
 * Refuse rather than force, throughout: a mirror that cannot fast-forward
 * means someone committed to it (a condition for a human); an existing
 * proposal branch for the same SHA means the round is already prepared.
 */
final class UpstreamSyncService
{
    public const STABLE_BRANCH = 'master';

    private const INTEGRATION_BRANCH_PREFIX = 'upstream-sync-';

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
        $mirror = $repo->run(['ls-remote', '--exit-code', 'origin', 'refs/heads/'.$branch]);

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
        $pushed = $repo->run(['push', 'origin', $upstreamSha.':refs/heads/'.$branch], timeout: 300);

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
     * Prepare an upstream integration proposal: `upstream-sync-<short-sha>`
     * from `origin/master` with the pinned upstream head merged in, entirely
     * in the object database, pushed for a human to open the PR. Stops there
     * by design. Does not depend on the mirror (#455) — the upstream head is
     * fetched and pinned directly.
     *
     * @return array{ok: bool, message: string, detail: string|null}
     */
    public function prepareIntegration(?Authenticatable $user): array
    {
        $this->gate->authorize($user);

        $path = base_path();
        $identity = $this->gitReader->upstreamIdentity($path);

        if ($identity === null) {
            return $this->failure((string) __('This checkout has no upstream remote, so there is nothing to integrate.'));
        }

        $repo = $this->syncRepository($path);
        $upstream = $this->resolveUpstreamHead($repo, $identity);

        if (! is_array($upstream)) {
            return $this->failure((string) __('Could not read the upstream head.'), $upstream);
        }

        [$branch, $upstreamSha] = $upstream;
        $proposalBranch = $this->integrationBranchName($upstreamSha);

        // Per-SHA proposal branches (#455): an existing branch for this exact
        // upstream SHA means the round is already prepared. Refuse with the
        // state named rather than force or reuse — and a failed lookup (exit
        // 128) is not "absent" (exit 2): preparing while GitHub is unreachable
        // must refuse, not proceed (sol's P1 on #356).
        $existing = $repo->run(['ls-remote', '--exit-code', 'origin', 'refs/heads/'.$proposalBranch]);

        if ($existing->ok) {
            return $this->failure((string) __('Refused: origin/:branch already exists — this upstream commit already has a proposal. Open its pull request, or delete the branch to prepare a fresh one.', ['branch' => $proposalBranch]));
        }

        if ($existing->exitCode !== 2) {
            return $this->failure((string) __('Could not determine whether origin/:branch already exists.', ['branch' => $proposalBranch]), $existing->message());
        }

        $fetched = $repo->run(['fetch', 'origin', self::STABLE_BRANCH], timeout: 300);

        if ($fetched->ok) {
            $fetched = $repo->run(['fetch', $identity['remote'], $branch], timeout: 300);
        }

        if (! $fetched->ok) {
            return $this->failure((string) __('Could not fetch origin/:stable and :remote/:branch.', ['stable' => self::STABLE_BRANCH, 'remote' => $identity['remote'], 'branch' => $branch]), $fetched->message());
        }

        $stableSha = $repo->output(['rev-parse', 'refs/remotes/origin/'.self::STABLE_BRANCH]);

        if ($stableSha === null) {
            return $this->failure((string) __('Could not resolve origin/:stable after fetching.', ['stable' => self::STABLE_BRANCH]));
        }

        // A real merge, written only to the object database: the active checkout
        // is untouched whether this succeeds or conflicts.
        $merge = $repo->run(['merge-tree', '--write-tree', '--name-only', $stableSha, $upstreamSha]);

        // merge-tree exits 1 for a conflicted merge (first output line is still a
        // tree oid, the rest are the conflicted paths) and >1 for a real error.
        if (! $merge->ok && $merge->exitCode === 1) {
            // Observed output shape (git 2.54): tree oid, the conflicted paths,
            // then a blank line followed by informational messages ("Auto-merging
            // …", "CONFLICT (content): …"). Only the section before the blank
            // line is file names.
            $lines = preg_split('/\R/', $merge->output) ?: [];
            $files = [];

            foreach (array_slice($lines, 1) as $line) {
                if (trim($line) === '') {
                    break;
                }

                $files[] = trim($line);
            }

            $conflicted = implode(', ', array_slice($files, 0, 20));

            return $this->failure(
                (string) __('The integration conflicts; the proposal was not created and the working tree was not touched. A person needs to resolve these files: :files', ['files' => $conflicted !== '' ? $conflicted : (string) __('(none listed)')]),
                $merge->output,
            );
        }

        if (! $merge->ok) {
            return $this->failure((string) __('Could not compute the integration merge.'), $merge->message());
        }

        $tree = (string) strtok($merge->output, "\n");
        $commit = $repo->run([
            'commit-tree', $tree,
            '-p', $stableSha,
            '-p', $upstreamSha,
            '-m', sprintf('upstream-sync: integrate %s/%s@%s into %s', $identity['remote'], $branch, substr($upstreamSha, 0, 7), self::STABLE_BRANCH),
        ]);

        if (! $commit->ok) {
            return $this->failure((string) __('Could not create the integration commit.'), $commit->message());
        }

        // The preflight absence check cannot be atomic with the push, so the push
        // itself carries the expectation: --force-with-lease with an empty value
        // is git's documented "must not exist" form. Measured (git 2.54): creates
        // the branch when absent, rejects with "(stale info)" when the same-named
        // branch appeared since — including a descendant, which a plain push
        // would fast-forward, letting two concurrent preparations both report
        // success (sol's P1 on #356).
        $pushed = $repo->run(['push', 'origin', $commit->output.':refs/heads/'.$proposalBranch, '--force-with-lease=refs/heads/'.$proposalBranch.':'], timeout: 300);

        if (! $pushed->ok && str_contains($pushed->message(), 'stale info')) {
            return $this->failure((string) __('Refused: origin/:branch appeared while this proposal was being prepared. Check whether another operator just prepared one for the same upstream commit.', ['branch' => $proposalBranch]), $pushed->message());
        }

        if (! $pushed->ok) {
            return $this->failure((string) __('Could not push :branch to origin.', ['branch' => $proposalBranch]), $pushed->message());
        }

        return [
            'ok' => true,
            'message' => (string) __('Integration proposal pushed as origin/:branch (:sha). Open the pull request :branch → :stable in GitHub to start UAT.', ['branch' => $proposalBranch, 'sha' => substr($commit->output, 0, 7), 'stable' => self::STABLE_BRANCH]),
            'detail' => null,
        ];
    }

    /**
     * The proposal branch name for a given upstream commit — deterministic in
     * the SHA so a repeated attempt at the same upstream commit names the same
     * branch rather than colliding with an unrelated one (#455).
     */
    public function integrationBranchName(string $upstreamSha): string
    {
        return self::INTEGRATION_BRANCH_PREFIX.substr($upstreamSha, 0, 7);
    }

    /**
     * @param  array{remote: string, branch: string|null, repo: string|null, url: string}  $identity
     * @return array{0: string, 1: string}|string|null [branch, sha], or an error detail
     */
    private function resolveUpstreamHead(GitRepository $repo, array $identity): array|string|null
    {
        if ($identity['branch'] !== null) {
            $result = $repo->run(['ls-remote', '--exit-code', $identity['remote'], 'refs/heads/'.$identity['branch']]);

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
