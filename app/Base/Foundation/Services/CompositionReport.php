<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Providers\ProviderRegistry;
use ReflectionClass;

/**
 * One read that says what this installation is composed of: every mounted
 * module with its declared version, its resolved boot position, and, for a
 * module that lives in a Domain checkout, the commit that checkout is at.
 *
 * Nothing here decides anything, and nothing here reads CI configuration: a
 * module's Domain is the mount directory it sits under, which is the same
 * convention the application already discovers Domains by (#623, #940).
 */
class CompositionReport
{
    /**
     * @return array{
     *     boot_order: list<string>,
     *     modules: list<array{module: string, name: string, version: string, layer: string, path: string, boot_position: int|null, provider: string|null, domain: string|null, mounted_ref: string|null}>
     * }
     */
    public function build(): array
    {
        $reader = new ModuleManifestReader([
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);

        $manifestsByRoot = [];
        foreach ($reader->all() as $manifest) {
            $manifestsByRoot[$this->normalize($manifest->path)] = $manifest;
        }

        $providersByRoot = [];
        foreach (ProviderRegistry::resolve() as $position => $provider) {
            $file = (new ReflectionClass($provider))->getFileName();
            if ($file !== false) {
                $providersByRoot[$this->normalize(dirname($file))] = ['position' => $position, 'provider' => $provider];
            }
        }

        $mountedRefs = [];
        $modules = [];

        foreach ($reader->moduleRoots() as $module => $root) {
            $root = $this->normalize($root);
            $manifest = $manifestsByRoot[$root] ?? null;
            $domain = $this->domainFor($root);
            $mountedRef = null;
            if ($domain !== null) {
                $mountedRefs[$domain] ??= $this->mountedRef($this->domainMount($domain));
                $mountedRef = $mountedRefs[$domain];
            }

            $modules[] = [
                'module' => $module,
                'name' => $manifest?->name ?? $module,
                'version' => $manifest?->version ?? '',
                'layer' => $this->layer($root),
                'path' => $this->relative($root),
                'boot_position' => $providersByRoot[$root]['position'] ?? null,
                'provider' => $providersByRoot[$root]['provider'] ?? null,
                'domain' => $domain,
                'mounted_ref' => $mountedRef,
            ];
        }

        usort($modules, fn (array $a, array $b): int => ($a['boot_position'] ?? PHP_INT_MAX) <=> ($b['boot_position'] ?? PHP_INT_MAX) ?: strcmp($a['module'], $b['module']));

        return [
            'boot_order' => array_values(array_map(
                fn (array $module): string => $module['module'],
                array_filter($modules, fn (array $module): bool => $module['boot_position'] !== null),
            )),
            'modules' => $modules,
        ];
    }

    /**
     * The Domain a module root belongs to: the directory directly under the
     * Domains root, lowercased and hyphenated back into its id, or null for a
     * module that is not in a Domain at all. `PeopleConnector` is
     * `people-connector`, the same rule scripts/ci/domain-registry.php applies
     * in the other direction.
     */
    private function domainFor(string $root): ?string
    {
        $domainsRoot = $this->normalize(ApplicationTopology::domainsRoot());
        if (! str_starts_with($root, $domainsRoot.'/')) {
            return null;
        }

        $name = explode('/', substr($root, strlen($domainsRoot) + 1))[0];
        if ($name === '') {
            return null;
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    /**
     * The mount directory of a Domain id, which is that id in StudlyCase under
     * the Domains root.
     */
    private function domainMount(string $domain): string
    {
        $studly = implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', $domain),
        ));

        return ApplicationTopology::domainsRoot().'/'.$studly;
    }

    /**
     * The commit a mounted checkout is at; null when it is not a checkout or
     * git does not answer with a commit sha. Only a sha is published: a mount
     * in a broken state (shallow, corrupt, a .git file pointing nowhere) is
     * exactly when an operator reads this page, and exactly when git prints
     * something that is not one.
     */
    private function mountedRef(string $mount): ?string
    {
        if (! is_dir($mount) || ! file_exists($mount.'/.git')) {
            return null;
        }

        [$exit, $output] = $this->gitHead($mount);

        return $exit === 0 && preg_match('/^[0-9a-f]{40}$/', $output) === 1 ? $output : null;
    }

    /**
     * Raw git answer for a mount's HEAD. Overridable so a test can hand the
     * shape check whatever git might print. Argv form, never a shell.
     *
     * @return array{0: int, 1: string} [exit code, trimmed stdout]
     */
    protected function gitHead(string $mount): array
    {
        $process = proc_open(['git', '-C', $mount, 'rev-parse', 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return [1, ''];
        }
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function layer(string $root): string
    {
        foreach (['base' => ApplicationTopology::baseRoot(), 'core' => ApplicationTopology::coreRoot(), 'domain' => ApplicationTopology::domainsRoot(), 'extension' => ApplicationTopology::extensionsRoot()] as $layer => $layerRoot) {
            if (str_starts_with($root, $this->normalize($layerRoot).'/')) {
                return $layer;
            }
        }

        return 'unknown';
    }

    private function relative(string $path): string
    {
        $base = $this->normalize(base_path());

        return str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
    }

    private function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }
}
