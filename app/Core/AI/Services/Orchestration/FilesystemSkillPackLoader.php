<?php

namespace App\Core\AI\Services\Orchestration;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use App\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Core\AI\DTO\Orchestration\SkillPackReference;
use App\Core\AI\Enums\SkillPackStatus;

/**
 * Discovers ownership-scoped filesystem skills by path contract.
 *
 * Roots (module-system discovery):
 * - `.agents/skills/` → `core.{slug}` (legacy-stable platform skill ids)
 * - `app/Base/{Component}/.agents/skills/` → `module.base.{component}.{slug}`
 * - `app/Core/{Module}/.agents/skills/` → `module.core.{module}.{slug}`
 * - `app/Domains/{Domain}/{Module}/.agents/skills/` → `module.{domain}.{module}.{slug}`
 * - `app/Extensions/{Extension}/.agents/skills/` → `extension.{extension}.{slug}`
 * - `app/Extensions/{Extension}/{Module}/.agents/skills/` → `extension.{extension}.{module}.{slug}`
 */
class FilesystemSkillPackLoader
{
    private const SKILLS_PATH = '/.agents/skills';

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

        foreach ($this->applicationModuleRoots() as $identity => $moduleRoot) {
            $roots[] = [
                'path' => $moduleRoot.self::SKILLS_PATH,
                'owner' => 'module:'.$identity,
                'id_prefix' => 'module.'.$identity,
            ];
        }

        foreach ($this->extensionSourceRoots() as $owner => $sourceRoot) {
            $roots[] = [
                'path' => $sourceRoot.self::SKILLS_PATH,
                'owner' => 'extension:'.$owner,
                'id_prefix' => 'extension.'.$owner,
            ];

            foreach ($this->extensionModuleRootsUnderSource($sourceRoot) as $module) {
                $roots[] = [
                    'path' => $sourceRoot.'/'.$module.self::SKILLS_PATH,
                    'owner' => 'extension:'.$owner.'/'.$module,
                    'id_prefix' => 'extension.'.$owner.'.'.$module,
                ];
            }
        }

        return $roots;
    }

    /**
     * @return array<string, string> logical module identity → absolute module root
     */
    private function applicationModuleRoots(): array
    {
        $roots = [];

        foreach (glob(ApplicationTopology::baseRoot().'/*', GLOB_ONLYDIR) ?: [] as $componentPath) {
            $component = $this->normalizeSlug(basename($componentPath));
            $roots['base.'.$component] = $componentPath;
        }

        foreach (glob(ApplicationTopology::coreRoot().'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
            $module = $this->normalizeSlug(basename($modulePath));
            $roots['core.'.$module] = $modulePath;
        }

        $domainPaths = DomainState::filterPaths(
            glob(ApplicationTopology::domainsRoot().'/*', GLOB_ONLYDIR) ?: [],
        );

        foreach ($domainPaths as $domainPath) {
            $domain = $this->normalizeSlug(basename($domainPath));

            foreach (glob($domainPath.'/*', GLOB_ONLYDIR) ?: [] as $modulePath) {
                $module = $this->normalizeSlug(basename($modulePath));
                $roots[$domain.'.'.$module] = $modulePath;
            }
        }

        return $roots;
    }

    /**
     * Extension source roots keyed by their stable logical slug.
     *
     * @return array<string, string>
     */
    private function extensionSourceRoots(): array
    {
        $roots = [];
        $basePath = ApplicationTopology::extensionsRoot();

        if (! is_dir($basePath)) {
            return [];
        }

        foreach (glob($basePath.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $roots[$this->normalizeSlug(basename($path))] = $path;
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function extensionModuleRootsUnderSource(string $sourceRoot): array
    {
        $modules = [];

        foreach (glob($sourceRoot.'/*', GLOB_ONLYDIR) ?: [] as $path) {
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
        $slug = str($slug)->kebab()->lower()->toString();

        return preg_replace('/[^a-z0-9._-]+/i', '-', $slug) ?: 'skill';
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
