<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Providers\ProviderRegistry;
use ReflectionClass;

/**
 * One read that says what this installation is composed of: every mounted
 * module with its declared version, its resolved boot position, and, for a
 * module that lives in a pinned Domain checkout, the ref the descriptor
 * (scripts/ci/domain-repos.json) pins beside the ref actually checked out.
 *
 * Nothing here decides anything. The smoke test (scripts/ci/composed-smoke.php)
 * refuses a composition whose mounts are not at their pins; this report lets an
 * operator see the same facts on a running installation (#623).
 */
final class CompositionReport
{
    public function __construct(private readonly ?string $descriptorPath = null) {}

    /**
     * @return array{
     *     descriptor: string|null,
     *     boot_order: list<string>,
     *     modules: list<array{module: string, name: string, version: string, layer: string, path: string, boot_position: int|null, provider: string|null, domain: string|null, pinned_ref: string|null, mounted_ref: string|null, matches_pin: bool|null}>
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

        $pins = $this->descriptorPins();
        $mountedRefs = [];
        $modules = [];

        foreach ($reader->moduleRoots() as $module => $root) {
            $root = $this->normalize($root);
            $manifest = $manifestsByRoot[$root] ?? null;
            $domain = $this->domainFor($root, $pins);
            $pinnedRef = $domain !== null ? $pins[$domain]['ref'] : null;
            $mountedRef = null;
            if ($domain !== null) {
                $mountedRefs[$domain] ??= $this->mountedRef(base_path($pins[$domain]['path']));
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
                'pinned_ref' => $pinnedRef,
                'mounted_ref' => $mountedRef,
                'matches_pin' => $pinnedRef === null || $mountedRef === null ? null : $pinnedRef === $mountedRef,
            ];
        }

        usort($modules, fn (array $a, array $b): int => ($a['boot_position'] ?? PHP_INT_MAX) <=> ($b['boot_position'] ?? PHP_INT_MAX) ?: strcmp($a['module'], $b['module']));

        return [
            'descriptor' => $pins === [] ? null : $this->relative($this->descriptor()),
            'boot_order' => array_values(array_map(
                fn (array $module): string => $module['module'],
                array_filter($modules, fn (array $module): bool => $module['boot_position'] !== null),
            )),
            'modules' => $modules,
        ];
    }

    /**
     * @return array<string, array{path: string, ref: string}> domain id => pin
     */
    private function descriptorPins(): array
    {
        $path = $this->descriptor();
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        $pins = [];
        foreach ((array) ($data['domains'] ?? []) as $id => $domain) {
            if (is_string($id) && is_array($domain) && isset($domain['path'], $domain['ref'])) {
                $pins[$id] = ['path' => trim((string) $domain['path'], '/'), 'ref' => (string) $domain['ref']];
            }
        }

        return $pins;
    }

    private function descriptor(): string
    {
        return $this->descriptorPath ?? base_path('scripts/ci/domain-repos.json');
    }

    /**
     * @param  array<string, array{path: string, ref: string}>  $pins
     */
    private function domainFor(string $root, array $pins): ?string
    {
        foreach ($pins as $id => $pin) {
            $mount = $this->normalize(base_path($pin['path']));
            if ($root === $mount || str_starts_with($root, $mount.'/')) {
                return $id;
            }
        }

        return null;
    }

    /** The commit a mounted checkout is at, read from git; null when it is not a checkout. */
    private function mountedRef(string $mount): ?string
    {
        if (! is_dir($mount) || ! file_exists($mount.'/.git')) {
            return null;
        }

        $process = proc_open(['git', '-C', $mount, 'rev-parse', 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && preg_match('/^[0-9a-f]{40}$/', $output) === 1 ? $output : null;
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
