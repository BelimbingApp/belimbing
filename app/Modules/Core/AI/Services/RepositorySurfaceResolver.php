<?php
namespace App\Modules\Core\AI\Services;

use App\Base\AI\Tools\ToolArgumentException;
use App\Modules\Core\AI\DTO\RepositorySurface;

class RepositorySurfaceResolver
{
    /**
     * Directory prefixes that belong to extension-owned code.
     *
     * @var list<string>
     */
    private const EXTENSION_PREFIXES = [
        'extensions/',
    ];

    public function resolve(?string $targetSurface = null): RepositorySurface
    {
        $targetSurface = trim($targetSurface ?: 'core');

        if ($targetSurface === 'core') {
            return new RepositorySurface(
                target: 'core',
                rootPath: base_path(),
                relativeRoot: '',
            );
        }

        if (! str_starts_with($targetSurface, 'extension:')) {
            throw new ToolArgumentException('target_surface must be "core" or "extension:<slug>".');
        }

        $slug = substr($targetSurface, strlen('extension:'));

        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            throw new ToolArgumentException('Extension slug contains invalid characters.');
        }

        foreach ($this->extensionCandidateRoots($slug) as $relativeRoot) {
            $rootPath = base_path($relativeRoot);

            if (is_dir($rootPath)) {
                return new RepositorySurface(
                    target: 'extension:'.$slug,
                    rootPath: $rootPath,
                    relativeRoot: $relativeRoot,
                    extensionSlug: $slug,
                );
            }
        }

        throw new ToolArgumentException("Extension surface \"{$slug}\" was not found.");
    }

    public function resolvePath(string $filePath, ?string $targetSurface = null): string
    {
        $this->validateRelativePath($filePath);

        $surface = $this->resolve($targetSurface);
        $normalized = $this->normalizeRelativePath($filePath);

        if ($surface->isCore()) {
            $this->assertCorePath($normalized);

            return $normalized;
        }

        if ($surface->relativeRoot !== '' && str_starts_with($normalized, $surface->relativeRoot.'/')) {
            return substr($normalized, strlen($surface->relativeRoot) + 1);
        }

        return $normalized;
    }

    public function absolutePath(string $filePath, ?string $targetSurface = null): string
    {
        $surface = $this->resolve($targetSurface);
        $relativePath = $this->resolvePath($filePath, $targetSurface);

        return $surface->rootPath.'/'.$relativePath;
    }

    public function displayPath(string $filePath, ?string $targetSurface = null): string
    {
        $surface = $this->resolve($targetSurface);
        $relativePath = $this->resolvePath($filePath, $targetSurface);

        if ($surface->relativeRoot === '') {
            return $relativePath;
        }

        return $surface->relativeRoot.'/'.$relativePath;
    }

    /**
     * @return list<string>
     */
    private function extensionCandidateRoots(string $slug): array
    {
        return [
            'extensions/'.$slug,
            'extensions/custom/'.$slug,
            'extensions/vendor/'.$slug,
        ];
    }

    private function validateRelativePath(string $filePath): void
    {
        if (trim($filePath) === '') {
            throw new ToolArgumentException('No file path provided.');
        }

        if (str_contains($filePath, '..')) {
            throw new ToolArgumentException('Path traversal ("..") is not allowed.');
        }

        if (str_starts_with($filePath, '/')) {
            throw new ToolArgumentException('Absolute paths are not allowed. Use paths relative to the target surface.');
        }
    }

    private function normalizeRelativePath(string $filePath): string
    {
        return trim(str_replace('\\', '/', $filePath), '/');
    }

    private function assertCorePath(string $filePath): void
    {
        foreach (self::EXTENSION_PREFIXES as $prefix) {
            if (str_starts_with($filePath, $prefix)) {
                throw new ToolArgumentException(
                    'This path belongs to an extension. Use target_surface "extension:<slug>" instead.'
                );
            }
        }
    }
}
