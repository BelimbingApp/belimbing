<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackReference;
use App\Modules\Core\AI\Enums\SkillPackStatus;

class FilesystemSkillPackLoader
{
    /**
     * @return list<SkillPackManifest>
     */
    public function load(): array
    {
        $manifests = [];

        foreach ($this->skillRoots() as $root) {
            foreach ($this->loadFromRoot($root['path'], $root['owner'], $root['id_prefix']) as $manifest) {
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

        foreach ($this->extensionRoots() as $slug => $extensionRoot) {
            $roots[] = [
                'path' => $extensionRoot.'/.agents/skills',
                'owner' => 'extension:'.$slug,
                'id_prefix' => 'extension.'.$slug,
            ];
        }

        return $roots;
    }

    /**
     * @return array<string, string>
     */
    private function extensionRoots(): array
    {
        $roots = [];

        foreach (['extensions', 'resources/extensions'] as $base) {
            $basePath = base_path($base);
            if (! is_dir($basePath)) {
                continue;
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
        }

        return $roots;
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
            $manifests[] = new SkillPackManifest(
                id: $id,
                version: '1.0.0',
                name: $this->titleFromSlug($slug),
                description: $this->descriptionFromContent($content),
                owner: $owner,
                promptResources: [
                    new SkillPackPromptResource(
                        label: 'skill-'.$this->normalizeSlug($slug),
                        content: '## Skill: '.$this->titleFromSlug($slug)."\n\n".$content,
                        order: 300,
                    ),
                ],
                references: [
                    new SkillPackReference(
                        title: $this->titleFromSlug($slug),
                        path: $this->relativePath($skillFile),
                        summary: $this->descriptionFromContent($content),
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

    private function descriptionFromContent(string $content): string
    {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line, " \t#");
            if ($line !== '') {
                return mb_substr($line, 0, 200);
            }
        }

        return 'Filesystem skill';
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;
    }
}
