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
class CompositionReport
{
    public function __construct(private readonly ?string $descriptorPath = null) {}

    /**
     * @return array{
     *     descriptor: string|null,
     *     descriptor_issues: list<string>,
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

        [$pins, $issues] = $this->descriptorPins();
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
            // The file that was read, whenever one exists: a descriptor whose
            // entries are unusable is a broken descriptor, not a missing one,
            // and the operator must be able to tell the two apart.
            'descriptor' => is_file($this->descriptor()) ? $this->relative($this->descriptor()) : null,
            'descriptor_issues' => $issues,
            'boot_order' => array_values(array_map(
                fn (array $module): string => $module['module'],
                array_filter($modules, fn (array $module): bool => $module['boot_position'] !== null),
            )),
            'modules' => $modules,
        ];
    }

    /**
     * Pins keyed on the mount path, which is what ties a module to a domain.
     * An entry with a path but no usable ref still claims its mount (the pin
     * is unreadable, not absent) and is reported as an issue; an entry with no
     * path cannot claim anything and is reported too.
     *
     * @return array{0: array<string, array{path: string, ref: string|null}>, 1: list<string>} [domain id => pin, issues]
     */
    private function descriptorPins(): array
    {
        $path = $this->descriptor();
        if (! is_file($path)) {
            return [[], []];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['domains'] ?? null)) {
            return [[], [$this->relative($path).' is not a descriptor: no domains object']];
        }

        $pins = [];
        $issues = [];
        foreach ($data['domains'] as $id => $domain) {
            $id = (string) $id;
            $mount = is_array($domain) && is_string($domain['path'] ?? null) ? trim($domain['path'], '/') : '';
            if ($mount === '') {
                $issues[] = "domain [{$id}] has no mount path";

                continue;
            }

            $ref = is_array($domain) && is_string($domain['ref'] ?? null) && preg_match('/^[0-9a-f]{40}$/', $domain['ref']) === 1
                ? $domain['ref']
                : null;
            if ($ref === null) {
                $issues[] = "domain [{$id}] at {$mount} has no immutable 40-character ref";
            }

            $pins[$id] = ['path' => $mount, 'ref' => $ref];
        }

        return [$pins, $issues];
    }

    private function descriptor(): string
    {
        return $this->descriptorPath ?? base_path('scripts/ci/domain-repos.json');
    }

    /**
     * @param  array<string, array{path: string, ref: string|null}>  $pins
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
     * shape check whatever git might print. Argv form, never a shell: the
     * mount path comes from the descriptor.
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
