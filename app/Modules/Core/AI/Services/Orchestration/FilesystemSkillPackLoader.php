<?php

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackReference;
use App\Modules\Core\AI\Enums\SkillPackStatus;

/**
 * Discovers ownership-scoped filesystem skills by path contract.
 *
 * Roots (module-system discovery):
 * - `.agents/skills/` → `core.{slug}`
 * - `extensions/{owner}/.agents/skills/` → `extension.{owner}.{slug}`
 * - `extensions/{owner}/{module}/.agents/skills/` → `extension.{owner}.{module}.{slug}`
 * - `app/Modules/{Domain}/{Module}/.agents/skills/` → `module.{domain}.{module}.{slug}`
 */
class FilesystemSkillPackLoader
{
    /**
     * @return list<SkillPackManifest>
     */
    public function load(): array
    {
        $manifests = [];
        $seenIds = [];

        foreach ($this->skillRoots() as $root) {
            foreach ($this->loadFromRoot($root['path'], $root['owner'], $root['id_prefix']) as $manifest) {
                if (isset($seenIds[$manifest->id])) {
                    continue;
                }

                $seenIds[$manifest->id] = true;
                $manifests[] = $manifest;
            }
        }

        return $manifests;
    }

    /**
     * @return list<array{path: string, owner: string, id_prefix: string}>
     */
    private function skillRoots(): array
    {
        $roots = [[
            'path' => base_path('.agents/skills'),
            'owner' => 'core',
            'id_prefix' => 'core',
        ]];

        foreach ($this->domainModuleRoots() as $identity => $moduleRoot) {
            $roots[] = [
                'path' => $moduleRoot.'/.agents/skills',
                'owner' => 'module:'.$identity,
                'id_prefix' => 'module.'.$identity,
            ];
        }

        foreach ($this->extensionBundleRoots() as $owner => $bundleRoot) {
            $roots[] = [
                'path' => $bundleRoot.'/.agents/skills',
                'owner' => 'extension:'.$owner,
                'id_prefix' => 'extension.'.$owner,
            ];

            foreach ($this->extensionModuleRootsUnderBundle($bundleRoot) as $module) {
                $roots[] = [
                    'path' => $bundleRoot.'/'.$module.'/.agents/skills',
                    'owner' => 'extension:'.$owner.'/'.$module,
                    'id_prefix' => 'extension.'.$owner.'.'.$module,
                ];
            }
        }

        return $roots;
    }

    /**
     * @return array<string, string> domain/module identity → absolute module root
     */
    private function domainModuleRoots(): array
    {
        $roots = [];
        $modulesBase = base_path('app/Modules');

        if (! is_dir($modulesBase)) {
            return [];
        }

        foreach (glob($modulesBase.'/*', GLOB_ONLYDIR) ?: [] as $domainPath) {
            $domain = $this->normalizeSlug(basename($domainPath));

            foreach (glob($domainPath.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
                $module = $this->normalizeSlug(basename($modulePath));
                $roots[$domain.'.'.$module] = $modulePath;
            }
        }

        return $roots;
    }

    /**
     * Extension bundle roots keyed by owner slug (kiat, custom/foo → foo, etc.).
     *
     * @return array<string, string>
     */
    private function extensionBundleRoots(): array
    {
        $roots = [];
        $basePath = base_path('extensions');

        if (! is_dir($basePath)) {
            return [];
        }

        foreach (glob($basePath.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);

            if (in_array($slug, ['custom', 'vendor'], true)) {
                foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $nested) {
                    $roots[basename($nested)] = $nested;
                }

                continue;
            }

            $roots[$slug] = $path;
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function extensionModuleRootsUnderBundle(string $bundleRoot): array
    {
        $modules = [];

        foreach (glob($bundleRoot.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);

            if (str_starts_with($name, '.') || in_array($name, ['docs', 'vendor', 'node_modules'], true)) {
                continue;
            }

            $modules[] = $name;
        }

        sort($modules);

        return $modules;
    }

    /**
     * @return list<SkillPackManifest>
     */
    private function loadFromRoot(string $root, string $owner, string $idPrefix): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $manifests = [];

        foreach (glob($root.'/*/SKILL.md') ?: [] as $skillFile) {
            $slug = basename(dirname($skillFile));
            $content = file_get_contents($skillFile);

            if ($content === false || trim($content) === '') {
                continue;
            }

            $id = $idPrefix.'.'.$this->normalizeSlug($slug);
            $name = $this->nameFromContent($content) ?? $this->titleFromSlug($slug);
            $description = $this->descriptionFromContent($content);

            $manifests[] = new SkillPackManifest(
                id: $id,
                version: '1.0.0',
                name: $name,
                description: $description,
                owner: $owner,
                promptResources: [
                    new SkillPackPromptResource(
                        label: 'skill-'.$this->normalizeSlug($slug),
                        content: '## Skill: '.$name."\n\n".$content,
                        order: 300,
                    ),
                ],
                references: [
                    new SkillPackReference(
                        title: $name,
                        path: $this->relativePath($skillFile),
                        summary: $description,
                    ),
                ],
                readinessChecks: ['SKILL.md exists and is readable'],
                status: SkillPackStatus::Ready,
            );
        }

        return $manifests;
    }

    private function normalizeSlug(string $slug): string
    {
        return preg_replace('/[^a-z0-9._-]+/i', '-', strtolower($slug)) ?: 'skill';
    }

    private function titleFromSlug(string $slug): string
    {
        return str($slug)->replace(['-', '_'], ' ')->title()->toString();
    }

    private function nameFromContent(string $content): ?string
    {
        $frontmatter = $this->frontmatter($content);
        $name = $frontmatter['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function descriptionFromContent(string $content): string
    {
        $frontmatter = $this->frontmatter($content);
        $description = $frontmatter['description'] ?? null;

        if (is_string($description) && trim($description) !== '') {
            return mb_substr(trim($description), 0, 200);
        }

        $body = $this->bodyWithoutFrontmatter($content);

        foreach (explode("\n", $body) as $line) {
            $line = trim($line, " \t#");
            if ($line !== '') {
                return mb_substr($line, 0, 200);
            }
        }

        return 'Filesystem skill';
    }

    /**
     * @return array<string, string>
     */
    private function frontmatter(string $content): array
    {
        if (! str_starts_with(ltrim($content), '---')) {
            return [];
        }

        if (! preg_match('/^---\s*\n(.*?)\n---\s*(?:\n|$)/s', ltrim($content), $matches)) {
            return [];
        }

        $fields = [];

        foreach (explode("\n", $matches[1]) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $fields[trim($key)] = trim(trim($value), '"\'');
        }

        return $fields;
    }

    private function bodyWithoutFrontmatter(string $content): string
    {
        $trimmed = ltrim($content);

        if (! preg_match('/^---\s*\n.*?\n---\s*(?:\n(.*))?$/s', $trimmed, $matches)) {
            return $content;
        }

        return $matches[1] ?? '';
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return str_starts_with($normalized, $base)
            ? str_replace('\\', '/', substr($normalized, strlen($base)))
            : str_replace('\\', '/', $path);
    }
}
