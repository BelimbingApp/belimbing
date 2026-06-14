<?php

namespace App\Base\Update\Services;

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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
    private const TOKEN_PREFIX = 'integrations.github.token.';

    private const LAST_RELOAD_KEY = 'system.update.frankenphp.last_reload';

    private const COMPOSER_RUN_KEY = 'system.update.composer.last_run';

    private const FRONTEND_RUN_KEY = 'system.update.frontend.last_run';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Per-Distribution Bundle version status for the Belimbing update page.
     *
     * @return list<array{key: string, label: string, path: string, owner: string|null, repo: string|null, branch: string|null, current: array<string, mixed>|null, latest: array<string, mixed>|null, up_to_date: bool|null, error: string|null}>
     */
    public function status(): array
    {
        return array_map(function (array $dist): array {
            [$owner, $name] = $this->remoteIdentity($dist['path']);
            $branch = $this->git($dist['path'], ['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'main';

            $entry = [
                'key' => $dist['key'],
                'label' => $dist['label'],
                'path' => $dist['relative'],
                'owner' => $owner,
                'repo' => $owner !== null ? $owner.'/'.$name : null,
                'branch' => $branch,
                'current' => $this->localCommit($dist['path']),
                'latest' => null,
                'up_to_date' => null,
                'error' => null,
            ];

            if ($owner === null) {
                $entry['error'] = (string) __('No GitHub origin remote.');

                return $entry;
            }

            [$latest, $error] = $this->latestCommit($dist['path'], $owner, $name, $branch);

            if ($latest === null) {
                $entry['error'] = $error;

                return $entry;
            }

            $entry['latest'] = $latest;
            $entry['up_to_date'] = $entry['current'] !== null && $entry['current']['sha'] === $latest['sha'];

            return $entry;
        }, $this->distributions());
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
        $byOwner = [];

        foreach ($this->distributions() as $dist) {
            [$owner, $name] = $this->remoteIdentity($dist['path']);

            if ($owner === null) {
                continue;
            }

            $byOwner[$owner]['owner'] = $owner;
            $byOwner[$owner]['repos'][] = $owner.'/'.$name;
        }

        return array_values(array_map(function (array $entry): array {
            $entry['has_token'] = $this->tokenFor($entry['owner']) !== null;

            return $entry;
        }, $byOwner));
    }

    /**
     * Probe each repo under one owner using that owner's token (public repos
     * resolve without it). Used by GitHub Access "Test connection".
     *
     * @return list<array{repo: string, ok: bool, status: int|null, message: string}>
     */
    public function testOwner(string $owner, ?string $token = null): array
    {
        $token = $token !== null && trim($token) !== '' ? trim($token) : $this->tokenFor($owner);
        $results = [];

        foreach ($this->distributions() as $dist) {
            [$repoOwner, $name] = $this->remoteIdentity($dist['path']);

            if ($repoOwner === null || ! $this->ownerMatches($repoOwner, $owner)) {
                continue;
            }

            $response = $this->githubGet($repoOwner, $name, '', $token);

            $results[] = [
                'repo' => "{$repoOwner}/{$name}",
                'ok' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful()
                    ? (string) ($response->json('private') ? __('Reachable (private).') : __('Reachable (public).'))
                    : (string) match ($response->status()) {
                        401 => __('Token rejected (401) — check the value.'),
                        403 => __('Forbidden (403) — the token lacks access to this repo.'),
                        404 => __('Not found (404) — private repo and the token is missing or lacks Contents: Read.'),
                        default => __('Failed (HTTP :status).', ['status' => $response->status()]),
                    },
            ];
        }

        return $results;
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
        $byKey = collect($this->distributions())->keyBy('key');
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
        $composerBefore = $this->composerLockHash();

        Artisan::call('down', ['--retry' => 5]);

        try {
            foreach ($targets as $dist) {
                $record((string) __('Pulling :label…', ['label' => $dist['label']]));
                $record($this->pull($dist));
            }

            // PHP dependencies: a changed composer.lock means a full install; otherwise
            // just refresh the optimized autoloader for any new/moved classes.
            if ($this->composerLockHash() !== $composerBefore) {
                $record((string) __('PHP dependencies changed — running composer install…'));
                $record($this->composerInstall());
            } else {
                $record((string) __('Refreshing autoloader…'));
                $record($this->composerDumpAutoload());
            }

            $record((string) __('Building frontend assets…'));
            $log = array_merge($log, $this->buildAssets($progress));

            $record((string) __('Running migrations…'));
            Artisan::call('migrate', ['--force' => true]);
            $record(trim(Artisan::output()) ?: (string) __('No pending migrations.'));
        } finally {
            Artisan::call('up');
        }

        foreach ($this->reload() as $line) {
            $record($line);
        }

        foreach ($this->verifyTargets($targets) as $line) {
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
        $log = [(string) __('Installing PHP dependencies…'), $this->composerInstall()];

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
            $this->buildAssets(),
            [(string) __('Done.')],
        );
    }

    /**
     * The JS package manager the asset build will actually invoke on this host
     * (bun|pnpm|yarn|npm, chosen by lockfile) — so the UI can name it honestly.
     */
    public function frontendPackageManager(): string
    {
        return $this->nodeInstallCommand()[0];
    }

    public function tokenFor(string $owner): ?string
    {
        $token = $this->settings->get(self::TOKEN_PREFIX.strtolower($owner));

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * Store (encrypted) or, when empty, clear the token for one owner.
     */
    public function saveToken(string $owner, string $token): void
    {
        $this->settings->set(self::TOKEN_PREFIX.strtolower($owner), trim($token), encrypted: true);
    }

    /**
     * Discover the deployment's Distribution Bundles: platform root + nested repos
     * under app/Modules/* (domains) and extensions/* (licensee extensions).
     *
     * @return list<array{key: string, label: string, path: string, relative: string}>
     */
    private function distributions(): array
    {
        $found = [[
            'key' => 'platform',
            'label' => (string) __('Belimbing (platform)'),
            'path' => base_path(),
            'relative' => '.',
        ]];

        foreach (glob(base_path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir($dir.'/.git')) {
                $found[] = $this->distribution($dir, (string) __('Module: :name', ['name' => basename($dir)]));
            }
        }

        foreach (glob(base_path('extensions/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir($dir.'/.git')) {
                $found[] = $this->distribution($dir, (string) __('Extension: :name', ['name' => basename($dir)]));

                continue;
            }

            // Licensee Distribution Bundle one level deeper (extensions/{licensee}/{module}).
            foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
                if (is_dir($sub.'/.git')) {
                    $found[] = $this->distribution($sub, (string) __('Extension: :name', ['name' => basename($dir).'/'.basename($sub)]));
                }
            }
        }

        return $found;
    }

    /**
     * @return array{key: string, label: string, path: string, relative: string}
     */
    private function distribution(string $path, string $label): array
    {
        $relative = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);

        return [
            'key' => str_replace(['/', '\\'], '-', $relative),
            'label' => $label,
            'path' => $path,
            'relative' => str_replace('\\', '/', $relative),
        ];
    }

    private function localCommit(string $path): ?array
    {
        // Unit separator (%x1f) keeps subjects with spaces/pipes intact.
        $line = $this->git($path, ['log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s']);

        if ($line === null || $line === '') {
            return null;
        }

        [$sha, $date, $author, $subject] = array_pad(explode("\x1f", $line), 4, '');

        return $this->commit($sha, $date, $author, $subject);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function latestCommit(string $path, string $owner, string $name, string $branch): array
    {
        $repo = "{$owner}/{$name}";
        $result = $this->lsRemote($path, $branch, $this->tokenFor($owner));

        if (! $result->successful()) {
            return [null, $this->remoteCommitError($repo, $branch, $result)];
        }

        $line = trim($result->output());
        $sha = (string) strtok($line, " \t");

        if ($sha === '' || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1) {
            return [null, (string) __('Git remote response for :repo@:branch did not include a commit SHA.', ['repo' => $repo, 'branch' => $branch])];
        }

        return [$this->remoteCommit($path, $sha), null];
    }

    private function remoteCommit(string $path, string $sha): array
    {
        $line = $this->git($path, ['show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', $sha]);

        if ($line !== null && $line !== '') {
            [$commitSha, $date, $author, $subject] = array_pad(explode("\x1f", $line), 4, '');

            return $this->commit($commitSha, $date, $author, $subject);
        }

        return $this->commit($sha, '', '', (string) __('Remote branch head'));
    }

    private function remoteCommitError(string $repo, string $branch, ProcessResult $result): string
    {
        $detail = $this->processError($result);

        return (string) __('Could not read latest commit for :repo@:branch via git ls-remote (:detail). Public repositories do not need a token; check the repo name, branch, or network access. If this repo is private, add a token in GitHub Access.', [
            'repo' => $repo,
            'branch' => $branch,
            'detail' => $detail,
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

    /**
     * GET the GitHub API unauthenticated first, so public repos read without a
     * token. A fine-grained token is scoped to one owner, so sending it to a
     * different owner's (even public) repo returns 403 — only fall back to the
     * token when the anonymous request is unauthorized/not-found (a private repo).
     */
    private function githubGet(string $owner, string $name, string $path, ?string $token): Response
    {
        $url = "/repos/{$owner}/{$name}{$path}";
        $base = Http::acceptJson()
            ->withUserAgent('Belimbing Update Checker')
            ->timeout(15)
            ->baseUrl('https://api.github.com');

        $response = $base->get($url);

        if (! $response->successful() && $token !== null && in_array($response->status(), [401, 403, 404], true)) {
            $response = $base->withToken($token)->get($url);
        }

        return $response;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function remoteIdentity(string $path): array
    {
        if (! is_dir($path.DIRECTORY_SEPARATOR.'.git')) {
            return [null, null];
        }

        $url = $this->git($path, ['remote', 'get-url', 'origin']);

        if ($url !== null && preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [null, null];
    }

    private function ownerMatches(string $a, string $b): bool
    {
        return strtolower($a) === strtolower($b);
    }

    private function lsRemote(string $path, string $branch, ?string $token): ProcessResult
    {
        $args = ['git'];

        if ($token !== null) {
            $args[] = '-c';
            $args[] = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$token);
        }

        return Process::path($path)
            ->timeout(30)
            ->run(array_merge($args, ['ls-remote', '--exit-code', 'origin', 'refs/heads/'.$branch]));
    }

    /**
     * Fast-forward pull one Distribution Bundle, authenticating with its owner's token
     * for private repos (public repos pull token-free). Never partial: ff-only.
     *
     * @param  array{label: string, path: string}  $dist
     */
    private function pull(array $dist): string
    {
        [$owner] = $this->remoteIdentity($dist['path']);
        $token = $owner !== null ? $this->tokenFor($owner) : null;

        $args = ['git'];

        if ($token !== null) {
            // Auth the private fetch via header — keeps the token out of the URL/reflog.
            $args[] = '-c';
            $args[] = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.$token);
        }

        $args = array_merge($args, ['pull', '--ff-only']);

        $result = Process::path($dist['path'])->timeout(180)->run($args);

        return $result->successful()
            ? (trim($result->output()) ?: (string) __('Already up to date.'))
            : (string) __('FAILED: :error', ['error' => trim($result->errorOutput() ?: $result->output())]);
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
     * Last recorded composer install (manual or auto), or null if none yet.
     *
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastComposerRun(): ?array
    {
        return $this->readRun(self::COMPOSER_RUN_KEY);
    }

    /**
     * Last recorded frontend build (manual or auto), or null if none yet.
     *
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastFrontendRun(): ?array
    {
        return $this->readRun(self::FRONTEND_RUN_KEY);
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
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    private function readRun(string $key): ?array
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

        return [
            'attempted_at' => $attemptedAt,
            'ok' => ($record['ok'] ?? false) === true,
            'message' => $message,
            'pm' => is_string($record['pm'] ?? null) ? $record['pm'] : null,
        ];
    }

    private function composerInstall(): string
    {
        $args = array_merge($this->composerCommand(), [
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--optimize-autoloader',
        ]);

        if (app()->isProduction()) {
            $args[] = '--no-dev';
        }

        $result = Process::path(base_path())->timeout(900)->run($args);

        $message = $result->successful()
            ? (string) __('PHP dependencies installed.')
            : (string) __('PHP dependency install failed: :error', ['error' => $this->processError($result)]);

        $this->rememberRun(self::COMPOSER_RUN_KEY, $result->successful(), $message);

        return $message;
    }

    private function composerDumpAutoload(): string
    {
        $result = Process::path(base_path())->timeout(180)->run(
            array_merge($this->composerCommand(), ['dump-autoload', '--optimize', '--no-interaction']),
        );

        return $result->successful()
            ? (string) __('Autoloader refreshed.')
            : (string) __('Autoload refresh failed: :error', ['error' => trim($result->errorOutput() ?: $result->output())]);
    }

    /**
     * @return list<string>
     */
    private function composerCommand(): array
    {
        $phar = base_path('storage/app/.devops/composer.phar');

        // Bundled-PHP deployments (native Windows) ship composer.phar; others use composer on PATH.
        return is_file($phar) ? [PHP_BINARY, $phar] : ['composer'];
    }

    /**
     * @param  (callable(string): void)|null  $progress
     * @return list<string>
     */
    private function buildAssets(?callable $progress = null): array
    {
        if (! is_file(base_path('package.json'))) {
            $line = (string) __('No package.json found; frontend assets were not rebuilt.');
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

        $install = $this->nodeInstallCommand();
        $pm = $install[0];
        $record((string) __('Installing frontend dependencies (:command)…', ['command' => implode(' ', $install)]));

        $installResult = Process::path(base_path())->timeout(900)->run($install);

        if (! $installResult->successful()) {
            $message = (string) __('Frontend dependency install failed: :error', ['error' => $this->processError($installResult)]);
            $record($message);
            $this->rememberRun(self::FRONTEND_RUN_KEY, false, $message, ['pm' => $pm]);

            return $log;
        }

        $record((string) __('Frontend dependencies installed.'));

        $build = $this->nodeBuildCommand();
        $record((string) __('Running frontend build (:command)…', ['command' => implode(' ', $build)]));

        $buildResult = Process::path(base_path())->timeout(600)->run($build);

        $message = $buildResult->successful()
            ? (string) __('Frontend assets built.')
            : (string) __('Frontend asset build failed: :error', ['error' => $this->processError($buildResult)]);
        $record($message);
        $this->rememberRun(self::FRONTEND_RUN_KEY, $buildResult->successful(), $message, ['pm' => $pm]);

        return $log;
    }

    /**
     * @return list<string>
     */
    private function nodeInstallCommand(): array
    {
        if (is_file(base_path('bun.lock'))) {
            $args = ['bun', 'install', '--frozen-lockfile'];

            if (DIRECTORY_SEPARATOR === '\\') {
                $args[] = '--backend';
                $args[] = 'copyfile';
            }

            return $args;
        }

        if (is_file(base_path('pnpm-lock.yaml'))) {
            return ['pnpm', 'install', '--frozen-lockfile'];
        }

        if (is_file(base_path('yarn.lock'))) {
            return ['yarn', 'install', '--frozen-lockfile'];
        }

        if (is_file(base_path('package-lock.json'))) {
            return ['npm', 'ci'];
        }

        return ['npm', 'install'];
    }

    /**
     * @return list<string>
     */
    private function nodeBuildCommand(): array
    {
        if (is_file(base_path('bun.lock'))) {
            return ['bun', 'run', 'build'];
        }

        if (is_file(base_path('pnpm-lock.yaml'))) {
            return ['pnpm', 'run', 'build'];
        }

        if (is_file(base_path('yarn.lock'))) {
            return ['yarn', 'run', 'build'];
        }

        return ['npm', 'run', 'build'];
    }

    private function composerLockHash(): ?string
    {
        $lock = base_path('composer.lock');

        return is_file($lock) ? (md5_file($lock) ?: null) : null;
    }

    /**
     * @param  list<array{key: string, label: string}>  $targets
     * @return list<string>
     */
    private function verifyTargets(array $targets): array
    {
        $status = collect($this->status())->keyBy('key');
        $lines = [];

        foreach ($targets as $target) {
            $entry = $status->get($target['key']);

            if (! is_array($entry)) {
                $lines[] = (string) __('Could not verify :label after update; refresh the page to check its current status.', ['label' => $target['label']]);

                continue;
            }

            if ($entry['up_to_date'] === true) {
                continue;
            }

            if ($entry['up_to_date'] === false) {
                $lines[] = (string) __('Still behind: :label is at :current, latest is :latest. The Update button remains because this checkout did not reach the GitHub branch head.', [
                    'label' => $target['label'],
                    'current' => $entry['current']['short'] ?? __('unknown'),
                    'latest' => $entry['latest']['short'] ?? __('unknown'),
                ]);

                continue;
            }

            $lines[] = (string) __('Could not verify :label after update: :error', [
                'label' => $target['label'],
                'error' => $entry['error'] ?? __('unknown status'),
            ]);
        }

        return $lines === []
            ? [(string) __('Verified: selected Distribution Bundles are up to date.')]
            : $lines;
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

    private function processError(ProcessResult $result): string
    {
        return trim($result->errorOutput() ?: $result->output())
            ?: (string) __('process exited with code :code', ['code' => $result->exitCode()]);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $path, array $args): ?string
    {
        $result = Process::path($path)->run(array_merge(['git'], $args));

        return $result->successful() ? trim($result->output()) : null;
    }
}
