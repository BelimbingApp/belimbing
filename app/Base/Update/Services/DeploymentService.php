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

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DistributionBundleRepository $bundles,
        private readonly DeploymentBuildRunner $buildRunner,
    ) {}

    /**
     * Per-Distribution Bundle version status for the Belimbing update page.
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
                $record($this->buildRunner->composerInstall());
            } else {
                $record((string) __('Refreshing autoloader…'));
                $record($this->buildRunner->composerDumpAutoload());
            }

            $record((string) __('Building frontend assets…'));
            $log = array_merge($log, $this->buildRunner->buildAssets($progress));

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
        $log = [(string) __('Rebuilding PHP dependencies…'), $this->buildRunner->composerInstall()];

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
            [(string) __('Rebuilding frontend assets…')],
            $this->buildRunner->buildAssets(),
            [(string) __('Done.')],
        );
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
        $host = getenv('CADDY_SERVER_ADMIN_HOST') ?: '127.0.0.1';
        $port = getenv('CADDY_SERVER_ADMIN_PORT') ?: '2019';
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
        $this->rememberLastReload($webReloaded, $reloadMessage, $adminUrl);

        return $log;
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, admin_url: string}|null
     */
    public function lastReload(): ?array
    {
        $record = $this->settings->get(self::LAST_RELOAD_KEY);

        if (! is_array($record)) {
            return null;
        }

        $attemptedAt = $record['attempted_at'] ?? null;
        $message = $record['message'] ?? null;
        $adminUrl = $record['admin_url'] ?? null;

        if (! is_string($attemptedAt) || ! is_string($message) || ! is_string($adminUrl)) {
            return null;
        }

        return [
            'attempted_at' => $attemptedAt,
            'ok' => ($record['ok'] ?? false) === true,
            'message' => $message,
            'admin_url' => $adminUrl,
        ];
    }

    private function rememberLastReload(bool $ok, string $message, string $adminUrl): void
    {
        $this->settings->set(self::LAST_RELOAD_KEY, [
            'attempted_at' => now()->utc()->toIso8601String(),
            'ok' => $ok,
            'message' => $message,
            'admin_url' => $adminUrl,
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
