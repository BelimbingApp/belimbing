<?php

namespace App\Base\Software\Services;

use Illuminate\Support\Facades\Artisan;

/**
 * Reads the deployment's git state and compares it to GitHub so the operator can
 * see, per Distribution Bundle, the current vs latest commit, its age, and whether it
 * is up to date — and manage the GitHub tokens needed to reach private repos.
 *
 * Per the module-system Distribution Bundle model, a deployment is the **platform root
 * plus each nested module/extension bundle** (domain modules under `app/Modules/*`,
 * licensee extensions under `extensions/*`). Those are *discovered*, never
 * hardcoded, so adding/removing a module or extension just works.
 *
 * GitHub access is **per owner**: a fine-grained token is scoped to one owner,
 * and a deployment may span several (open-source modules under `BelimbingApp`,
 * private licensee extensions under their own accounts/orgs). The owner is read
 * live from each bundle's remote, so an ownership transfer (e.g. a repo
 * moving to a new org) is picked up automatically. Public owners need no token;
 * private owners each store one at `integrations.github.token.{owner}`.
 */
class DeploymentService
{
    private readonly DeploymentWorkerReloader $workerReloader;

    public function __construct(
        private readonly DistributionBundleRepository $bundles,
        private readonly DeploymentBuildRunner $buildRunner,
        private readonly DeploymentAdminEndpointResolver $adminEndpoints,
        private readonly DeploymentRunHistory $history,
        ?DeploymentWorkerReloader $workerReloader = null,
    ) {
        $this->workerReloader = $workerReloader ?? new DeploymentWorkerReloader($this->adminEndpoints, $this->history);
    }

    /**
     * Per-Distribution Bundle version status for the Deployment page.
     *
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function status(): array
    {
        return $this->bundles->status();
    }

    /**
     * Initial page status: local branch/current/working-tree state without remote
     * latest checks, so the Updates screen can render before network Git finishes.
     *
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, working_tree: array{dirty: int, ahead: int, behind: int}, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function localStatus(): array
    {
        return $this->bundles->status(includeRemote: false);
    }

    /**
     * The distinct GitHub owners across the deployment's Distribution Bundles, with the
     * repos under each and whether a token is stored — drives GitHub Access.
     * Local-only (no network); reachability is checked on demand via testOwner().
     *
     * @return list<array{owner: string, repos: list<string>, has_token: bool}>
     */
    public function owners(): array
    {
        return $this->bundles->owners();
    }

    /**
     * Probe each repo under one owner using that owner's token (public repos
     * resolve without it). Used by GitHub Access "Test connection".
     *
     * @return list<array{repo: string, ok: bool, status: int|null, message: string}>
     */
    public function testOwner(string $owner, ?string $token = null): array
    {
        return $this->bundles->testOwner($owner, $token);
    }

    /**
     * Pull the given Distribution Bundles (by key; empty = all), then refresh autoload,
     * migrate, and gracefully reload workers — under maintenance mode. Each repo
     * pulls with its owner's token (public repos pull token-free). Returns a log.
     *
     * @param  list<string>  $keys
     * @param  (callable(string): void)|null  $progress
     * @param  (callable(): void)|null  $beforeReload
     * @return list<string>
     */
    public function update(
        array $keys = [],
        ?callable $progress = null,
        bool $reloadWorkers = true,
        bool $manageMaintenance = true,
        ?callable $beforeReload = null,
    ): array {
        $targets = $this->updateTargets($keys);

        if ($targets === []) {
            $line = (string) __('Nothing to update.');
            if ($progress !== null) {
                $progress($line);
            }

            return [$line];
        }

        $log = [];
        $record = static function (string $line) use (&$log, $progress): void {
            $log[] = $line;
            if ($progress !== null) {
                $progress($line);
            }
        };
        $composerBefore = $this->buildRunner->composerLockHash();

        if ($manageMaintenance) {
            Artisan::call('down', ['--retry' => 5]);
        }

        try {
            $this->pullTargets($targets, $record);
            $this->refreshPhpDependencies($composerBefore, $record);

            if (DeploymentLogClassifier::hasError($log)) {
                $record((string) __('FAILED: dependency refresh did not complete; deployment halted before migrations and reload.'));

                return $log;
            }

            $this->buildFrontendAssets($log, $record, $progress);

            if (DeploymentLogClassifier::hasError($log)) {
                $record((string) __('FAILED: frontend assets did not build; deployment halted before migrations and reload.'));

                return $log;
            }

            $record((string) __('Running migrations…'));
            $migration = $this->buildRunner->migrate();
            $record($migration['output']);

            if ($migration['status'] !== 0) {
                $record((string) __('FAILED: database migrations did not complete; deployment halted before reload.'));

                return $log;
            }
        } finally {
            if ($manageMaintenance) {
                Artisan::call('up');
            }
        }

        if ($beforeReload !== null) {
            $beforeReload();
        }

        if ($reloadWorkers) {
            foreach ($this->reload() as $line) {
                $record($line);
            }
        }

        foreach ($this->bundles->verifyTargets($targets) as $line) {
            $record($line);
        }

        $record($this->completionLine($log, $reloadWorkers));

        return $log;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function updateTargets(array $keys): array
    {
        $byKey = collect($this->bundles->distributions())->keyBy('key');

        return $keys === [] ? $byKey->values()->all() : $byKey->only($keys)->values()->all();
    }

    /**
     * @param  list<array{key: string, label: string, path: string, relative: string}>  $targets
     * @param  callable(string): void  $record
     */
    private function pullTargets(array $targets, callable $record): void
    {
        foreach ($targets as $dist) {
            $record((string) __('Pulling :label…', ['label' => $dist['label']]));
            $record($this->bundles->pull($dist));
        }
    }

    /**
     * @param  callable(string): void  $record
     */
    private function refreshPhpDependencies(?string $composerBefore, callable $record): void
    {
        // A changed composer.lock means a full install; otherwise just refresh
        // the optimized autoloader for any new or moved classes.
        if ($this->buildRunner->composerLockHash() !== $composerBefore) {
            $record((string) __('PHP dependencies changed — running composer install…'));
            $record($this->runComposerInstall());

            return;
        }

        $record((string) __('Refreshing autoloader…'));
        $record($this->buildRunner->composerDumpAutoload());
    }

    /**
     * @param  list<string>  $log
     * @param  callable(string): void  $record
     * @param  (callable(string): void)|null  $progress
     */
    private function buildFrontendAssets(array &$log, callable $record, ?callable $progress): void
    {
        $record((string) __('Building frontend assets…'));
        $log = array_merge($log, $this->runFrontendBuild($progress));
    }

    /**
     * Manually reinstall PHP dependencies (composer install) and reload workers so
     * the new vendor tree takes effect. The escape hatch for the auto-step in update().
     *
     * @return list<string>
     */
    public function rebuildPhp(bool $reloadWorkers = true): array
    {
        $log = [(string) __('Installing PHP dependencies…'), $this->runComposerInstall()];

        if (! $reloadWorkers) {
            return array_merge($log, [(string) __('PHP dependencies installed; runtime reload must still run before workers use the refreshed vendor tree.')]);
        }

        return array_merge($log, $this->reload(), [(string) __('Done.')]);
    }

    /**
     * Manually reinstall frontend dependencies and rebuild assets. Built files are
     * served statically, so no worker reload is needed.
     *
     * @return list<string>
     */
    public function rebuildAssets(): array
    {
        return array_merge(
            [(string) __('Building frontend assets…')],
            $this->runFrontendBuild(),
            [(string) __('Done.')],
        );
    }

    /**
     * The JS package manager the asset build will actually invoke on this host
     * (bun|pnpm|yarn|npm, chosen by lockfile) — so the UI can name it honestly.
     */
    public function frontendPackageManager(): string
    {
        return $this->buildRunner->frontendPackageManager();
    }

    public function tokenFor(string $owner): ?string
    {
        return $this->bundles->tokenFor($owner);
    }

    /**
     * Store (encrypted) or, when empty, clear the token for one owner.
     */
    public function saveToken(string $owner, string $token): void
    {
        $this->bundles->saveToken($owner, $token);
    }

    private function runComposerInstall(): string
    {
        $message = $this->buildRunner->composerInstall();

        $this->history->rememberComposerRun(! DeploymentLogClassifier::hasError([$message]), $message);

        return $message;
    }

    /**
     * @param  (callable(string): void)|null  $progress
     * @return list<string>
     */
    private function runFrontendBuild(?callable $progress = null): array
    {
        $log = $this->buildRunner->buildAssets($progress);

        $this->history->rememberFrontendRun(
            ! DeploymentLogClassifier::hasError($log),
            $this->lastFrontendBuildMessage($log),
            $this->frontendPackageManager(),
        );

        return $log;
    }

    /**
     * @param  list<string>  $log
     */
    private function lastFrontendBuildMessage(array $log): string
    {
        $lastKey = array_key_last($log);

        return $lastKey !== null
            ? $log[$lastKey]
            : (string) __('Frontend build produced no output.');
    }

    public function reload(bool $clearRuntimeCaches = true): array
    {
        return $this->workerReloader->reload($clearRuntimeCaches);
    }

    /**
     * @param  list<string>  $log
     */
    private function completionLine(array $log, bool $reloadWorkers): string
    {
        if (DeploymentLogClassifier::hasError($log)) {
            return (string) __('Update finished with errors. Some steps did not complete; the Distribution Bundle table and log show what still needs attention.');
        }

        if (DeploymentLogClassifier::hasWarning($log)) {
            if (DeploymentLogClassifier::hasVerificationWarning($log)) {
                return (string) __('Update finished with warnings. One or more selected Distribution Bundles could not be verified at the branch head; review the lines above before trusting the table.');
            }

            return (string) __('Update finished with warnings. Pull, build, and migration steps completed, but one or more follow-up checks need attention.');
        }

        if (! $reloadWorkers) {
            return (string) __('Update complete. Selected Distribution Bundles are up to date; runtime reload still needs to run separately.');
        }

        return (string) __('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');
    }
}
