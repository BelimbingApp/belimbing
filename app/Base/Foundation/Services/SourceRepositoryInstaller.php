<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Events\DomainLifecycleAction;
use App\Base\Foundation\Exceptions\SourceRepositoryInstallException;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Software\Inventory\InstalledSource;
use App\Base\Software\Services\GitHubTokenStore;
use App\Base\Support\Git\GitRepository;
use App\Base\Support\PhpCli;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Installs one operator-supplied GitHub repository as a Domain or Extension.
 *
 * The public interface keeps the risky sequence behind one boundary: validate
 * the URL and destination, clone with an explicitly selected stored credential,
 * prove the checkout shape and manifests, run only that source's migrations,
 * and remove failed source trees whenever rollback makes that safe.
 */
final class SourceRepositoryInstaller
{
    public function __construct(
        private readonly GitHubTokenStore $tokens,
        private readonly DomainRuntimeReloader $runtimeReloader,
    ) {}

    /** @return list<string> */
    public function credentialOwners(): array
    {
        return $this->tokens->owners();
    }

    /**
     * @return array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}
     */
    public function install(
        string $repositoryUrl,
        string $kind,
        string $folder,
        ?string $credentialOwner = null,
    ): array {
        $repository = $this->repository($repositoryUrl);
        $kind = strtolower(trim($kind));
        $folder = trim($folder);
        $credentialOwner = is_string($credentialOwner) && trim($credentialOwner) !== ''
            ? strtolower(trim($credentialOwner))
            : null;

        if (! in_array($kind, [InstalledSource::KIND_DOMAIN, InstalledSource::KIND_EXTENSION], true)) {
            throw new SourceRepositoryInstallException((string) __('Choose Domain or Extension placement.'));
        }

        if (! $this->validFolder($folder)) {
            throw new SourceRepositoryInstallException((string) __('Source name must be PascalCase using letters and numbers only.'));
        }

        $token = $credentialOwner !== null ? $this->tokens->tokenFor($credentialOwner) : null;
        if ($credentialOwner !== null && $token === null) {
            throw new SourceRepositoryInstallException((string) __('No stored GitHub credential exists for :owner.', ['owner' => $credentialOwner]));
        }

        $path = $kind === InstalledSource::KIND_DOMAIN
            ? ApplicationTopology::domainPath($folder)
            : ApplicationTopology::extensionPath($folder);

        if (is_dir($path) && ! $this->directoryIsEmpty($path)) {
            throw new SourceRepositoryInstallException((string) __(':name is already installed as a :kind.', ['name' => $folder, 'kind' => $kind]));
        }

        if (is_dir($path)) {
            @rmdir($path);
        }

        $log = ['$ git clone '.$repository['url'].' '.$this->relativePath($path)];
        $clone = (new GitRepository(base_path(), $token))
            ->run(['clone', $repository['url'], $path], authenticated: true, timeout: 300);
        $log[] = trim($clone->output."\n".$clone->error);

        if (! $clone->ok) {
            $cleaned = $this->deleteCheckout($path);
            $log[] = $cleaned
                ? (string) __('Clone failed. The incomplete checkout was removed; verify the URL and selected GitHub credential, then retry.')
                : (string) __('Clone failed and the incomplete checkout could not be removed. Remove :path before retrying.', ['path' => $this->relativePath($path)]);

            $this->recordDomainInstall($kind, $folder, 'clone_failed', $repository['url'], $clone->exitCode);

            return $this->result(false, $log, $cleaned, $repository['url'], $kind, $folder);
        }

        try {
            $migrationPaths = $this->validateCheckout($path, $kind, $folder);
        } catch (Throwable $exception) {
            $cleaned = $this->deleteCheckout($path);
            $log[] = (string) __('Manifest validation failed: :message', ['message' => $exception->getMessage()]);
            $log[] = $cleaned
                ? (string) __('The rejected checkout was removed. No migrations ran.')
                : (string) __('The rejected checkout could not be removed. Remove :path before retrying.', ['path' => $this->relativePath($path)]);

            $this->recordDomainInstall($kind, $folder, 'manifest_failed', $repository['url']);

            return $this->result(false, $log, $cleaned, $repository['url'], $kind, $folder);
        }

        $beforeBatch = Schema::hasTable('migrations')
            ? (int) (DB::table('migrations')->max('batch') ?? 0)
            : 0;
        $migrateArgs = array_merge(
            ['migrate', '--force'],
            array_map(fn (string $migrationPath): string => '--path='.$migrationPath, $migrationPaths),
        );
        $migrate = null;
        if ($migrationPaths === []) {
            $log[] = (string) __('No source migrations declared; database migration step skipped.');
        } else {
            try {
                $migrate = Process::path(base_path())
                    ->timeout(600)
                    ->run(PhpCli::current()->artisan($migrateArgs));
            } catch (Throwable $exception) {
                $log[] = (string) __('The migration process could not be started or observed: :message', ['message' => $exception->getMessage()]);
                $log[] = (string) __('The checkout remains at :path because database state could not be verified. Resolve the process failure before retrying or removing it.', ['path' => $this->relativePath($path)]);
                $this->recordDomainInstall($kind, $folder, 'migration_failed', $repository['url']);

                return $this->result(false, $log, false, $repository['url'], $kind, $folder);
            }

            $log[] = '$ php artisan '.implode(' ', $migrateArgs);
            $log[] = trim($migrate->output()."\n".$migrate->errorOutput());
        }

        if ($migrate !== null && ! $migrate->successful()) {
            $rollbackArgs = array_merge(
                ['migrate:rollback', '--force', '--batch='.($beforeBatch + 1)],
                array_map(fn (string $migrationPath): string => '--path='.$migrationPath, $migrationPaths),
            );
            try {
                $rollback = Process::path(base_path())
                    ->timeout(600)
                    ->run(PhpCli::current()->artisan($rollbackArgs));
                $rollbackSuccessful = $rollback->successful();
                $rollbackOutput = trim($rollback->output()."\n".$rollback->errorOutput());
            } catch (Throwable $exception) {
                $rollbackSuccessful = false;
                $rollbackOutput = (string) __('Rollback process could not be started or observed: :message', ['message' => $exception->getMessage()]);
            }

            $log[] = '$ php artisan '.implode(' ', $rollbackArgs);
            $log[] = $rollbackOutput;

            $cleaned = $rollbackSuccessful && $this->deleteCheckout($path);
            $log[] = $cleaned
                ? (string) __('Migrations failed. BLB rolled back this source batch and removed the checkout; correct the repository and retry.')
                : (string) __('Migrations failed and automatic rollback did not finish cleanly. The checkout remains at :path so its code still describes any database state. Resolve the run log before retrying or removing it.', ['path' => $this->relativePath($path)]);

            $this->recordDomainInstall($kind, $folder, 'migration_failed', $repository['url'], $migrate->exitCode());

            return $this->result(false, $log, $cleaned, $repository['url'], $kind, $folder);
        }

        if ($kind === InstalledSource::KIND_DOMAIN) {
            DomainState::enable($folder);
        }

        try {
            $log = array_merge($log, $this->runtimeReloader->reloadAfterDomainChange());
        } catch (Throwable $exception) {
            $log[] = (string) __('The source and its migrations are installed, but runtime reload failed: :message', ['message' => $exception->getMessage()]);
            $log[] = (string) __('The checkout remains at :path. Resolve the runtime reload failure, then reload the application before retrying any lifecycle action.', ['path' => $this->relativePath($path)]);
            $this->recordDomainInstall($kind, $folder, 'runtime_reload_failed', $repository['url']);

            return $this->result(false, $log, false, $repository['url'], $kind, $folder);
        }

        $log[] = (string) __('Done — :name installed as a :kind. Its modules are live from the next page load.', [
            'name' => $folder,
            'kind' => $kind,
        ]);

        $this->recordDomainInstall($kind, $folder, 'succeeded', $repository['url'], $migrate?->exitCode() ?? 0);

        return $this->result(true, $log, false, $repository['url'], $kind, $folder);
    }

    /**
     * @return array{owner: string, name: string, url: string}
     */
    private function repository(string $repositoryUrl): array
    {
        $repositoryUrl = trim($repositoryUrl);
        $parts = parse_url($repositoryUrl);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'], $parts['query'], $parts['fragment'])) {
            throw new SourceRepositoryInstallException((string) __('Use an HTTPS GitHub repository URL without embedded credentials, query parameters, or fragments.'));
        }

        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')), 'strlen'));
        if (count($segments) !== 2) {
            throw new SourceRepositoryInstallException((string) __('GitHub repository URL must identify exactly one owner and repository.'));
        }

        [$owner, $name] = $segments;
        $name = preg_replace('/\.git$/i', '', $name) ?? $name;

        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $owner) !== 1
            || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw new SourceRepositoryInstallException((string) __('GitHub owner or repository name is invalid.'));
        }

        return [
            'owner' => strtolower($owner),
            'name' => $name,
            'url' => 'https://github.com/'.$owner.'/'.$name,
        ];
    }

    /**
     * @return list<string> repository-relative migration paths
     */
    private function validateCheckout(string $path, string $kind, string $folder): array
    {
        $providers = glob($path.'/*/ServiceProvider.php') ?: [];
        if ($providers === []) {
            throw new SourceRepositoryInstallException((string) __('Repository must contain at least one Module with ServiceProvider.php.'));
        }

        foreach ($providers as $provider) {
            $modulePath = dirname($provider);
            $module = basename($modulePath);
            if (! $this->validFolder($module)) {
                throw new SourceRepositoryInstallException((string) __('Module folder :module must be PascalCase.', ['module' => $module]));
            }

            $expectedNamespace = $kind === InstalledSource::KIND_DOMAIN
                ? "App\\Domains\\$folder\\$module"
                : "App\\Extensions\\$folder\\$module";
            $providerSource = File::get($provider);
            if (preg_match('/namespace\s+'.preg_quote($expectedNamespace, '/').'\s*;/', $providerSource) !== 1) {
                throw new SourceRepositoryInstallException((string) __('Module :module must declare namespace :namespace for the selected placement.', [
                    'module' => $module,
                    'namespace' => $expectedNamespace,
                ]));
            }

            $composerPath = $modulePath.'/composer.json';
            if (! is_file($composerPath)) {
                throw new SourceRepositoryInstallException((string) __('Module :module must include composer.json with an extra.blb manifest.', ['module' => $module]));
            }

            $composer = json_decode(File::get($composerPath), true, flags: JSON_THROW_ON_ERROR);
            $manifest = is_array($composer) ? ($composer['extra']['blb'] ?? null) : null;
            $moduleId = is_array($manifest) ? ($manifest['module'] ?? null) : null;
            if (! is_string($moduleId)
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $moduleId) !== 1) {
                throw new SourceRepositoryInstallException((string) __('Module :module must declare a stable lowercase extra.blb.module identity.', ['module' => $module]));
            }
        }

        $reader = new ModuleManifestReader([
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);
        $manifests = $reader->all();
        $sourceManifests = array_values(array_filter(
            $manifests,
            fn (ModuleManifest $manifest): bool => str_starts_with(
                str_replace('\\', '/', $manifest->path).'/',
                rtrim(str_replace('\\', '/', $path), '/').'/',
            ),
        ));

        if (count($sourceManifests) !== count($providers)) {
            throw new SourceRepositoryInstallException((string) __('Every Module must have one readable extra.blb manifest.'));
        }

        $sourceModuleIds = array_fill_keys(array_map(fn (ModuleManifest $manifest): string => $manifest->module, $sourceManifests), true);
        $dependencyIssues = array_values(array_filter(
            $reader->dependencyIssues($manifests),
            fn (array $issue): bool => isset($sourceModuleIds[(string) ($issue['requiring_module'] ?? '')]),
        ));
        if ($dependencyIssues !== []) {
            $issue = $dependencyIssues[0];
            $detail = $issue['issue'] === 'missing'
                ? "requires missing Module [{$issue['required']}]"
                : "requires [{$issue['required']}] {$issue['constraint']}, installed {$issue['installed_version']}";
            throw new SourceRepositoryInstallException((string) __('Module :module :detail.', [
                'module' => $issue['requiring_module'],
                'detail' => $detail,
            ]));
        }

        $migrationPaths = [];
        foreach ($providers as $provider) {
            $migrationPath = dirname($provider).'/Database/Migrations';
            if (is_dir($migrationPath)) {
                $migrationPaths[] = $this->relativePath($migrationPath);
            }
        }

        sort($migrationPaths);

        return $migrationPaths;
    }

    private function validFolder(string $folder): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $folder) === 1;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }

    private function deleteCheckout(string $path): bool
    {
        return ! is_dir($path) || File::deleteDirectory($path);
    }

    private function directoryIsEmpty(string $path): bool
    {
        return (scandir($path) ?: []) === ['.', '..'];
    }

    private function recordDomainInstall(
        string $kind,
        string $folder,
        string $status,
        string $repository,
        ?int $exitCode = null,
    ): void {
        if ($kind !== InstalledSource::KIND_DOMAIN) {
            return;
        }

        event(new DomainLifecycleAction($folder, 'install', $status, array_filter([
            'repo' => $repository,
            'exit_code' => $exitCode,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @param  list<string>  $log
     * @return array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}
     */
    private function result(
        bool $ok,
        array $log,
        bool $cleanedUp,
        string $repository,
        string $kind,
        string $folder,
    ): array {
        return [
            'ok' => $ok,
            'log' => implode("\n", array_filter($log, fn (string $line): bool => trim($line) !== '')),
            'cleaned_up' => $cleanedUp,
            'repository' => $repository,
            'kind' => $kind,
            'folder' => $folder,
        ];
    }
}
