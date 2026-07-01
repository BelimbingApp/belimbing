<?php

namespace App\Base\Software\Services;

use App\Base\Support\PhpCli;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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
    private const ADMIN_CONNECT_TIMEOUT_SECONDS = 2;

    private const ADMIN_REQUEST_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly DistributionBundleRepository $bundles,
        private readonly DeploymentBuildRunner $buildRunner,
        private readonly DeploymentAdminEndpointResolver $adminEndpoints,
        private readonly DeploymentRunHistory $history,
    ) {}

    /**
     * Per-Distribution Bundle version status for the Deployment page.
     *
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function status(): array
    {
        return $this->bundles->status();
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
     * @return list<string>
     */
    public function update(array $keys = [], ?callable $progress = null): array
    {
        $byKey = collect($this->bundles->distributions())->keyBy('key');
        $targets = $keys === [] ? $byKey->values()->all() : $byKey->only($keys)->values()->all();

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

        Artisan::call('down', ['--retry' => 5]);

        try {
            foreach ($targets as $dist) {
                $record((string) __('Pulling :label…', ['label' => $dist['label']]));
                $record($this->bundles->pull($dist));
            }

            // PHP dependencies: a changed composer.lock means a full install; otherwise
            // just refresh the optimized autoloader for any new/moved classes.
            if ($this->buildRunner->composerLockHash() !== $composerBefore) {
                $record((string) __('PHP dependencies changed — running composer install…'));
                $record($this->runComposerInstall());
            } else {
                $record((string) __('Refreshing autoloader…'));
                $record($this->buildRunner->composerDumpAutoload());
            }

            if ($this->hasError($log)) {
                $record((string) __('FAILED: dependency refresh did not complete; deployment halted before migrations and reload.'));

                return $log;
            }

            $record((string) __('Building frontend assets…'));
            $log = array_merge($log, $this->runFrontendBuild($progress));

            if ($this->hasError($log)) {
                $record((string) __('FAILED: frontend assets did not build; deployment halted before migrations and reload.'));

                return $log;
            }

            $record((string) __('Running migrations…'));
            $migrateStatus = Artisan::call('migrate', ['--force' => true]);
            $record(trim(Artisan::output()) ?: (string) __('No pending migrations.'));

            if ($migrateStatus !== 0) {
                $record((string) __('FAILED: database migrations did not complete; deployment halted before reload.'));

                return $log;
            }
        } finally {
            Artisan::call('up');
        }

        foreach ($this->reload() as $line) {
            $record($line);
        }

        foreach ($this->bundles->verifyTargets($targets) as $line) {
            $record($line);
        }

        $record($this->completionLine($log));

        return $log;
    }

    /**
     * Manually reinstall PHP dependencies (composer install) and reload workers so
     * the new vendor tree takes effect. The escape hatch for the auto-step in update().
     *
     * @return list<string>
     */
    public function rebuildPhp(): array
    {
        $log = [(string) __('Installing PHP dependencies…'), $this->runComposerInstall()];

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

        $this->history->rememberComposerRun(! $this->hasError([$message]), $message);

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
            ! $this->hasError($log),
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

    /**
     * Graceful, non-elevated worker reload: probe the Caddy admin API for a
     * FrankenPHP worker config, POST FrankenPHP's worker restart endpoint, then
     * queue:restart. The scheduler self-refreshes per run.
     *
     * @return list<string>
     */
    public function reload(bool $clearRuntimeCaches = true): array
    {
        $log = [];

        if ($clearRuntimeCaches) {
            $log[] = $this->clearRuntimeCaches();
        }

        $log[] = $this->warmRuntimeBootstrap();

        $webReloaded = false;
        $reloadMessage = '';
        $adminUrl = '';
        $candidates = $this->adminEndpoints->candidates();

        Log::debug('FrankenPHP worker reload probing admin API candidates.', [
            'candidates' => array_map(
                static fn (array $candidate): string => "{$candidate[0]}:{$candidate[1]}",
                $candidates,
            ),
        ]);

        foreach ($candidates as [$host, $port]) {
            $configUrl = "http://{$host}:{$port}/config/apps/frankenphp";
            $restartUrl = "http://{$host}:{$port}/frankenphp/workers/restart";
            $adminUrl = $configUrl;

            try {
                $config = $this->sendFrankenPhpAdminRequest(
                    fn (): Response => $this->frankenPhpAdminHttp()->get($configUrl),
                );

                if ($this->frankenPhpWorkerConfigPresent($config)) {
                    $adminUrl = $restartUrl;
                    $restart = $this->sendFrankenPhpAdminRequest(
                        fn (): Response => $this->frankenPhpAdminHttp()->post($restartUrl),
                    );

                    if ($restart->successful()) {
                        $webReloaded = true;
                        $reloadMessage = (string) __('Web workers reloaded.');
                        Log::debug('FrankenPHP worker reload succeeded.', ['admin_url' => $restartUrl]);

                        break;
                    }

                    $reloadMessage = (string) __('Warning: web workers were not reloaded; the FrankenPHP admin API returned HTTP :status. Running workers may keep old code until they restart.', ['status' => $restart->status()]);
                    Log::debug('FrankenPHP worker restart failed.', [
                        'admin_url' => $restartUrl,
                        'status' => $restart->status(),
                    ]);

                    continue;
                }

                $reloadMessage = (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url did not expose worker config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.', ['url' => $configUrl]);
                Log::debug('FrankenPHP worker reload GET returned no worker config.', [
                    'admin_url' => $configUrl,
                    'status' => $config->status(),
                ]);
            } catch (\Throwable $exception) {
                $reloadMessage = (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url could not be reached: :message', ['url' => $adminUrl, 'message' => $exception->getMessage()]);
                Log::debug('FrankenPHP worker reload request failed.', [
                    'admin_url' => $adminUrl,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $log[] = $reloadMessage;

        Artisan::call('queue:restart');
        $log[] = (string) __('Queue restart signaled.');
        $this->history->rememberReload($webReloaded, $reloadMessage, $adminUrl);

        return $log;
    }

    private function frankenPhpAdminHttp(): PendingRequest
    {
        return Http::connectTimeout(self::ADMIN_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::ADMIN_REQUEST_TIMEOUT_SECONDS);
    }

    private function frankenPhpWorkerConfigPresent(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $config = $response->json();

        return is_array($config) && array_key_exists('workers', $config);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function sendFrankenPhpAdminRequest(callable $request): Response
    {
        try {
            return $request();
        } catch (\Throwable $exception) {
            if (! $this->isHttpTimeout($exception)) {
                throw $exception;
            }

            return $request();
        }
    }

    private function isHttpTimeout(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'cURL error 28')
            || str_contains(strtolower($message), 'timed out');
    }

    private function clearRuntimeCaches(): string
    {
        Artisan::call('optimize:clear');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return (string) __('Runtime caches cleared.');
    }

    /**
     * FrankenPHP boots many workers concurrently. When the provider list changes
     * (for example, enabling/disabling a domain), letting every worker compile
     * bootstrap/cache/services.php at once is unreliable on Windows. Warm it once
     * in a normal CLI process before asking FrankenPHP to respawn the pool.
     */
    private function warmRuntimeBootstrap(): string
    {
        $warm = Process::path(base_path())
            ->timeout(60)
            ->run(PhpCli::current()->artisan(['about', '--only=environment']));

        if ($warm->successful()) {
            return (string) __('Runtime bootstrap warmed.');
        }

        $output = trim($warm->output()."\n".$warm->errorOutput());

        return (string) __('Warning: runtime bootstrap warmup failed before worker reload: :message', [
            'message' => $output !== '' ? $output : __('process exited with code :code', ['code' => $warm->exitCode()]),
        ]);
    }

    /**
     * @param  list<string>  $log
     */
    private function completionLine(array $log): string
    {
        if ($this->hasError($log)) {
            return (string) __('Update finished with errors. Some steps did not complete; the Distribution Bundle table and log show what still needs attention.');
        }

        if ($this->hasWarning($log)) {
            if ($this->hasVerificationWarning($log)) {
                return (string) __('Update finished with warnings. One or more selected Distribution Bundles could not be verified at the branch head; review the lines above before trusting the table.');
            }

            return (string) __('Update finished with warnings. Pull, build, and migration steps completed, but one or more follow-up checks need attention.');
        }

        return (string) __('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');
    }

    /**
     * @param  list<string>  $log
     */
    private function hasError(array $log): bool
    {
        return collect($log)->contains(function (string $line): bool {
            $lower = strtolower($line);

            return str_starts_with($line, 'FAILED:')
                || str_contains($lower, ' install failed:')
                || str_contains($lower, ' build failed:')
                || str_contains($lower, ' refresh failed:');
        });
    }

    /**
     * @param  list<string>  $log
     */
    private function hasWarning(array $log): bool
    {
        return collect($log)->contains(fn (string $line): bool => str_starts_with($line, 'Warning:')
            || str_starts_with($line, 'Still behind:')
            || str_starts_with($line, 'Could not verify'));
    }

    /**
     * @param  list<string>  $log
     */
    private function hasVerificationWarning(array $log): bool
    {
        return collect($log)->contains(fn (string $line): bool => str_starts_with($line, 'Still behind:')
            || str_starts_with($line, 'Could not verify'));
    }
}
