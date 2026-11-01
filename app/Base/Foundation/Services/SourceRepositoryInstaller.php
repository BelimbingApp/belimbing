<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Events\DomainLifecycleAction;
use App\Base\Foundation\Exceptions\SourceRepositoryInstallException;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\RuntimeRequirements\RuntimeRequirementVerifier;
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
        private readonly RuntimeRequirementVerifier $runtimeRequirements,
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
        $installation = $this->installation($repositoryUrl, $kind, $folder, $credentialOwner);
        $repository = $installation['repository'];
        $kind = $installation['kind'];
        $folder = $installation['folder'];
        $path = $installation['path'];

        $log = ['$ git clone '.$repository['url'].' '.$this->relativePath($path)];
        $clone = (new GitRepository(base_path(), $installation['token']))
            ->run(['clone', $repository['url'], $path], authenticated: true, timeout: 300);
        $log[] = trim($clone->output."\n".$clone->error);

        if (! $clone->ok) {
            return $this->cloneFailure($path, $log, $kind, $folder, $repository['url'], $clone->exitCode);
        }

        try {
            $migrationPaths = (new SourceRepositoryCheckoutValidator)->migrationPaths($path, $kind, $folder);
        } catch (Throwable $exception) {
            return $this->manifestFailure($path, $log, $kind, $folder, $repository['url'], $exception);
        }

        $runtimeFailure = $this->runtimeRequirementsFailure($path, $log, $kind, $folder, $repository['url']);
        if ($runtimeFailure !== null) {
            return $runtimeFailure;
        }

        $migration = $this->runMigrations($path, $migrationPaths, $log, $kind, $folder, $repository['url']);
        if ($migration['failure'] !== null) {
            return $migration['failure'];
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

        $this->recordDomainInstall($kind, $folder, 'succeeded', $repository['url'], $migration['exit_code']);

        return $this->result(true, $log, false, $repository['url'], $kind, $folder);
    }

    /** @return array{repository: array{owner: string, name: string, url: string}, kind: string, folder: string, token: ?string, path: string} */
    private function installation(string $repositoryUrl, string $kind, string $folder, ?string $credentialOwner): array
    {
        $repository = $this->repository($repositoryUrl);
        $kind = strtolower(trim($kind));
        $folder = trim($folder);
        $credentialOwner = is_string($credentialOwner) && trim($credentialOwner) !== '' ? strtolower(trim($credentialOwner)) : null;

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

        $path = $kind === InstalledSource::KIND_DOMAIN ? ApplicationTopology::domainPath($folder) : ApplicationTopology::extensionPath($folder);
        if (is_dir($path) && ! $this->directoryIsEmpty($path)) {
            throw new SourceRepositoryInstallException((string) __(':name is already installed as a :kind.', ['name' => $folder, 'kind' => $kind]));
        }

        if (is_dir($path)) {
            @rmdir($path);
        }

        return compact('repository', 'kind', 'folder', 'token', 'path');
    }

    /** @param list<string> $log
     * @return array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}
     */
    private function cloneFailure(string $path, array $log, string $kind, string $folder, string $repositoryUrl, int $exitCode): array
    {
        $cleaned = $this->deleteCheckout($path);
        $log[] = $cleaned ? (string) __('Clone failed. The incomplete checkout was removed; verify the URL and selected GitHub credential, then retry.') : (string) __('Clone failed and the incomplete checkout could not be removed. Remove :path before retrying.', ['path' => $this->relativePath($path)]);
        $this->recordDomainInstall($kind, $folder, 'clone_failed', $repositoryUrl, $exitCode);

        return $this->result(false, $log, $cleaned, $repositoryUrl, $kind, $folder);
    }

    /** @param list<string> $log
     * @return array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}
     */
    private function manifestFailure(string $path, array $log, string $kind, string $folder, string $repositoryUrl, Throwable $exception): array
    {
        $cleaned = $this->deleteCheckout($path);
        $log[] = (string) __('Manifest validation failed: :message', ['message' => $exception->getMessage()]);
        $log[] = $cleaned ? (string) __('The rejected checkout was removed. No migrations ran.') : (string) __('The rejected checkout could not be removed. Remove :path before retrying.', ['path' => $this->relativePath($path)]);
        $this->recordDomainInstall($kind, $folder, 'manifest_failed', $repositoryUrl);

        return $this->result(false, $log, $cleaned, $repositoryUrl, $kind, $folder);
    }

    /**
     * @param  list<string>  $log
     * @return array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}|null
     */
    private function runtimeRequirementsFailure(string $path, array &$log, string $kind, string $folder, string $repositoryUrl): ?array
    {
        $requirements = $this->runtimeRequirementsFor($path);
        $unmetRequirements = $this->runtimeRequirements->unmet($requirements);
        if ($unmetRequirements === []) {
            return null;
        }

        $cleaned = $this->deleteCheckout($path);
        $log[] = implode("\n", $unmetRequirements);
        $setupCommands = $this->runtimeRequirements->setupCommands($requirements);
        if ($setupCommands !== []) {
            $log[] = 'No migrations ran. Run '.implode(' then ', $setupCommands).' as the deployment operator, set BLB_SYSTEM_PHP_BINARY when that CLI is not on PATH, then retry the source install.';
        }
        $log[] = $cleaned
            ? (string) __('The checkout was removed because its host-runtime prerequisites are not ready.')
            : (string) __('The checkout has unmet host-runtime prerequisites and could not be removed. Remove :path before retrying.', ['path' => $this->relativePath($path)]);

        return $this->result(false, $log, $cleaned, $repositoryUrl, $kind, $folder);
    }

    /**
     * @param  list<string>  $migrationPaths
     * @param  list<string>  $log
     * @return array{failure: array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}|null, exit_code: int}
     */
    private function runMigrations(string $path, array $migrationPaths, array &$log, string $kind, string $folder, string $repositoryUrl): array
    {
        $beforeBatch = Schema::hasTable('migrations') ? (int) (DB::table('migrations')->max('batch') ?? 0) : 0;
        $migrateArgs = array_merge(['migrate', '--force'], array_map(fn (string $migrationPath): string => '--path='.$migrationPath, $migrationPaths));
        if ($migrationPaths === []) {
            $log[] = (string) __('No source migrations declared; database migration step skipped.');

            return ['failure' => null, 'exit_code' => 0];
        }

        try {
            $migrate = Process::path(base_path())->timeout(600)->run(PhpCli::current()->artisan($migrateArgs));
        } catch (Throwable $exception) {
            $log[] = (string) __('The migration process could not be started or observed: :message', ['message' => $exception->getMessage()]);
            $log[] = (string) __('The checkout remains at :path because database state could not be verified. Resolve the process failure before retrying or removing it.', ['path' => $this->relativePath($path)]);
            $this->recordDomainInstall($kind, $folder, 'migration_failed', $repositoryUrl);

            return ['failure' => $this->result(false, $log, false, $repositoryUrl, $kind, $folder), 'exit_code' => 1];
        }

        $log[] = '$ php artisan '.implode(' ', $migrateArgs);
        $log[] = trim($migrate->output()."\n".$migrate->errorOutput());
        if ($migrate->successful()) {
            return ['failure' => null, 'exit_code' => $migrate->exitCode()];
        }

        $failure = $this->migrationFailure($path, $migrationPaths, $beforeBatch, $log, $kind, $folder, $repositoryUrl, $migrate->exitCode());

        return ['failure' => $failure, 'exit_code' => $migrate->exitCode()];
    }

    /**
     * @param  list<string>  $migrationPaths
     * @param  list<string>  $log
     * @return array{ok: bool, log: string, cleaned_up: bool, repository: string, kind: string, folder: string}
     */
    private function migrationFailure(string $path, array $migrationPaths, int $beforeBatch, array $log, string $kind, string $folder, string $repositoryUrl, int $exitCode): array
    {
        $rollbackArgs = array_merge(['migrate:rollback', '--force', '--batch='.($beforeBatch + 1)], array_map(fn (string $migrationPath): string => '--path='.$migrationPath, $migrationPaths));
        try {
            $rollback = Process::path(base_path())->timeout(600)->run(PhpCli::current()->artisan($rollbackArgs));
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
        $this->recordDomainInstall($kind, $folder, 'migration_failed', $repositoryUrl, $exitCode);

        return $this->result(false, $log, $cleaned, $repositoryUrl, $kind, $folder);
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
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new SourceRepositoryInstallException((string) __('Use an HTTPS GitHub repository URL without embedded credentials, query parameters, or fragments.'));
        }

        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')), 'strlen'));
        if (count($segments) !== 2) {
            throw new SourceRepositoryInstallException((string) __('GitHub repository URL must identify exactly one owner and repository.'));
        }

        [$owner, $name] = $segments;
        $name = preg_replace('/\.git$/i', '', $name) ?? $name;

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/', $owner) !== 1
            || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw new SourceRepositoryInstallException((string) __('GitHub owner or repository name is invalid.'));
        }

        return [
            'owner' => strtolower($owner),
            'name' => $name,
            'url' => 'https://github.com/'.$owner.'/'.$name,
        ];
    }

    private function validFolder(string $folder): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $folder) === 1;
    }

    /** @return list<string> */
    private function runtimeRequirementsFor(string $path): array
    {
        $manifests = (new ModuleManifestReader([$path]))->all();
        $requirements = [];

        foreach ($manifests as $manifest) {
            $requirements = [...$requirements, ...$manifest->runtimeRequirements];
        }

        return array_values(array_unique($requirements));
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
