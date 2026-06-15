<?php

namespace App\Base\Update\Services;

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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
    private const LAST_RELOAD_KEY = 'system.update.frankenphp.last_reload';

    private const COMPOSER_RUN_KEY = 'system.update.composer.last_run';

    private const FRONTEND_RUN_KEY = 'system.update.frontend.last_run';

    private const DEPLOYMENT_RUN_KEY = 'system.update.deployment.last_run';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DistributionBundleRepository $bundles,
        private readonly DeploymentBuildRunner $buildRunner,
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

            $record((string) __('Building frontend assets…'));
            $log = array_merge($log, $this->runFrontendBuild($progress));

            $record((string) __('Running migrations…'));
            Artisan::call('migrate', ['--force' => true]);
            $record(trim(Artisan::output()) ?: (string) __('No pending migrations.'));
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

        $this->rememberRun(self::COMPOSER_RUN_KEY, ! $this->hasError([$message]), $message);

        return $message;
    }

    /**
     * @param  (callable(string): void)|null  $progress
     * @return list<string>
     */
    private function runFrontendBuild(?callable $progress = null): array
    {
        $log = $this->buildRunner->buildAssets($progress);

        $this->rememberRun(
            self::FRONTEND_RUN_KEY,
            ! $this->hasError($log),
            $this->lastFrontendBuildMessage($log),
            ['pm' => $this->frontendPackageManager()],
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
     * Graceful, non-elevated worker reload: PATCH the Caddy admin config back to
     * force FrankenPHP to respawn the worker pool (what octane:reload does under
     * the hood), then queue:restart. The scheduler self-refreshes per run.
     *
     * @return list<string>
     */
    public function reload(): array
    {
        $log = [];
        [$host, $port] = $this->resolveAdminEndpoint();
        $adminUrl = "http://{$host}:{$port}/config/apps/frankenphp";
        $webReloaded = false;
        $reloadMessage = '';

        try {
            $config = Http::timeout(3)->get($adminUrl);

            if ($config->successful() && trim($config->body()) !== '') {
                $patch = Http::withBody($config->body(), 'application/json')
                    ->withHeaders(['Cache-Control' => 'must-revalidate'])
                    ->timeout(3)
                    ->patch($adminUrl);

                if ($patch->successful()) {
                    $webReloaded = true;
                    $reloadMessage = (string) __('Web workers reloaded.');
                } else {
                    $reloadMessage = (string) __('Warning: web workers were not reloaded; the FrankenPHP admin API returned HTTP :status. Running workers may keep old code until they restart.', ['status' => $patch->status()]);
                }
            } else {
                $reloadMessage = (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url did not respond with config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.', ['url' => $adminUrl]);
            }
        } catch (\Throwable $exception) {
            $reloadMessage = (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url could not be reached: :message', ['url' => $adminUrl, 'message' => $exception->getMessage()]);
        }

        $log[] = $reloadMessage;

        Artisan::call('queue:restart');
        $log[] = (string) __('Queue restart signaled.');
        $this->rememberRun(self::LAST_RELOAD_KEY, $webReloaded, $reloadMessage, ['admin_url' => $adminUrl]);

        return $log;
    }

    /**
     * Resolve the host+port the FrankenPHP/Caddy admin API is actually listening on.
     * octane:start records it in its server-state file, so we read it from there (the
     * same source octane:reload trusts) rather than guessing — the stock Caddy admin
     * port 2019 is wrong for our setups (octane runs the admin on e.g. 2020 on dev,
     * 2643 on prod). Explicit env vars still win for non-Octane or unusual hosts.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveAdminEndpoint(): array
    {
        $host = getenv('CADDY_SERVER_ADMIN_HOST') ?: null;
        $port = getenv('CADDY_SERVER_ADMIN_PORT') ?: null;

        if ($host === null || $port === null) {
            $statePath = storage_path('logs/octane-server-state.json');
            $state = is_file($statePath)
                ? json_decode((string) file_get_contents($statePath), true)
                : null;

            if (is_array($state)) {
                // Octane nests the live values under "state" (see FrankenPhp\ServerProcessInspector);
                // fall back to the top level defensively in case that layout changes.
                $admin = is_array($state['state'] ?? null) ? $state['state'] : $state;
                $host ??= is_string($admin['adminHost'] ?? null) ? $admin['adminHost'] : null;
                $port ??= isset($admin['adminPort']) ? (string) $admin['adminPort'] : null;
            }
        }

        return [$host ?: '127.0.0.1', $port ?: '2019'];
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, admin_url: string}|null
     */
    public function lastReload(): ?array
    {
        $run = $this->readRun(self::LAST_RELOAD_KEY, ['admin_url' => true]);

        /** @var array{attempted_at: string, ok: bool, message: string, admin_url: string}|null $run */
        return $run;
    }

    /**
     * Last recorded composer install (manual or auto), or null if none yet.
     *
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastComposerRun(): ?array
    {
        $run = $this->readRun(self::COMPOSER_RUN_KEY, ['pm' => false]);

        /** @var array{attempted_at: string, ok: bool, message: string, pm: string|null}|null $run */
        return $run;
    }

    /**
     * Last recorded frontend build (manual or auto), or null if none yet.
     *
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastFrontendRun(): ?array
    {
        $run = $this->readRun(self::FRONTEND_RUN_KEY, ['pm' => false]);

        /** @var array{attempted_at: string, ok: bool, message: string, pm: string|null}|null $run */
        return $run;
    }

    /**
     * Record the run shown in the Deployment page's run box so its outcome and time
     * survive a page reload or a brand-new session — the durable counterpart to the
     * session-scoped live log. Status is the page's run outcome (success|warning|error).
     *
     * @param  list<string>  $log
     */
    public function rememberDeploymentRun(array $log, string $status): void
    {
        $this->settings->set(self::DEPLOYMENT_RUN_KEY, [
            'attempted_at' => now()->utc()->toIso8601String(),
            'status' => $status,
            'summary' => $log === [] ? '' : (string) $log[array_key_last($log)],
            'log' => array_values($log),
        ]);
    }

    /**
     * The last recorded Deployment run (update/reload/rebuild), or null if none yet.
     *
     * @return array{attempted_at: string, status: string, summary: string, log: list<string>}|null
     */
    public function lastDeploymentRun(): ?array
    {
        $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);

        if (! is_array($record)) {
            return null;
        }

        $attemptedAt = $record['attempted_at'] ?? null;
        $status = $record['status'] ?? null;

        if (! is_string($attemptedAt) || ! is_string($status)) {
            return null;
        }

        return [
            'attempted_at' => $attemptedAt,
            'status' => $status,
            'summary' => is_string($record['summary'] ?? null) ? $record['summary'] : '',
            'log' => array_values(array_filter(
                is_array($record['log'] ?? null) ? $record['log'] : [],
                'is_string',
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function rememberRun(string $key, bool $ok, string $message, array $extra = []): void
    {
        $this->settings->set($key, array_merge([
            'attempted_at' => now()->utc()->toIso8601String(),
            'ok' => $ok,
            'message' => $message,
        ], $extra));
    }

    /**
     * @param  array<string, bool>  $stringFields  field => required
     * @return array<string, bool|string|null>|null
     */
    private function readRun(string $key, array $stringFields = []): ?array
    {
        $record = $this->settings->get($key);

        if (! is_array($record)) {
            return null;
        }

        $attemptedAt = $record['attempted_at'] ?? null;
        $message = $record['message'] ?? null;

        if (! is_string($attemptedAt) || ! is_string($message)) {
            return null;
        }

        $run = [
            'attempted_at' => $attemptedAt,
            'ok' => ($record['ok'] ?? false) === true,
            'message' => $message,
        ];

        foreach ($stringFields as $field => $required) {
            $value = $record[$field] ?? null;

            if ($required && ! is_string($value)) {
                return null;
            }

            $run[$field] = is_string($value) ? $value : null;
        }

        return $run;
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
            return (string) __('Update finished with warnings. Code may be updated, but one or more follow-up checks need attention.');
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
}
