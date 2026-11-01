<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Exceptions\SourceRepositoryInstallException;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Software\Inventory\InstalledSource;
use Illuminate\Support\Facades\File;

/**
 * Proves that a newly cloned source is a valid BLB Domain or Extension before
 * its migrations become eligible to run.
 */
final class SourceRepositoryCheckoutValidator
{
    /**
     * @return list<string> repository-relative migration paths
     */
    public function migrationPaths(string $path, string $kind, string $folder): array
    {
        $providers = glob($path.'/*/ServiceProvider.php') ?: [];
        if ($providers === []) {
            throw new SourceRepositoryInstallException((string) __('Repository must contain at least one Module with ServiceProvider.php.'));
        }

        foreach ($providers as $provider) {
            $this->validateProvider($provider, $kind, $folder);
        }

        $reader = new ModuleManifestReader([
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);
        $sourceManifests = $this->sourceManifests($reader, $providers, $path);
        $this->validateDependencies($reader, $sourceManifests);

        return $this->migrationPathsFor($providers);
    }

    private function validateProvider(string $provider, string $kind, string $folder): void
    {
        $modulePath = dirname($provider);
        $module = basename($modulePath);
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $module) !== 1) {
            throw new SourceRepositoryInstallException((string) __('Module folder :module must be PascalCase.', ['module' => $module]));
        }

        $expectedNamespace = $kind === InstalledSource::KIND_DOMAIN
            ? "App\\Domains\\$folder\\$module"
            : "App\\Extensions\\$folder\\$module";
        if (preg_match('/namespace\s+'.preg_quote($expectedNamespace, '/').'\s*;/', File::get($provider)) !== 1) {
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

    /**
     * @param  list<string>  $providers
     * @return list<ModuleManifest>
     */
    private function sourceManifests(ModuleManifestReader $reader, array $providers, string $path): array
    {
        $manifests = $reader->all();
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/').'/';
        $sourceManifests = array_values(array_filter(
            $manifests,
            fn (ModuleManifest $manifest): bool => str_starts_with(
                str_replace('\\', '/', $manifest->path).'/',
                $normalizedPath,
            ),
        ));

        if (count($sourceManifests) !== count($providers)) {
            throw new SourceRepositoryInstallException((string) __('Every Module must have one readable extra.blb manifest.'));
        }

        return $sourceManifests;
    }

    /** @param list<ModuleManifest> $sourceManifests */
    private function validateDependencies(ModuleManifestReader $reader, array $sourceManifests): void
    {
        $sourceModuleIds = array_fill_keys(array_map(fn (ModuleManifest $manifest): string => $manifest->module, $sourceManifests), true);
        $dependencyIssues = array_values(array_filter(
            $reader->dependencyIssues($reader->all()),
            fn (array $issue): bool => isset($sourceModuleIds[(string) ($issue['requiring_module'] ?? '')]),
        ));
        if ($dependencyIssues === []) {
            return;
        }

        $issue = $dependencyIssues[0];
        $detail = $issue['issue'] === 'missing'
            ? "requires missing Module [{$issue['required']}]"
            : "requires [{$issue['required']}] {$issue['constraint']}, installed {$issue['installed_version']}";
        throw new SourceRepositoryInstallException((string) __('Module :module :detail.', [
            'module' => $issue['requiring_module'],
            'detail' => $detail,
        ]));
    }

    /**
     * @param  list<string>  $providers
     * @return list<string>
     */
    private function migrationPathsFor(array $providers): array
    {
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

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }
}
