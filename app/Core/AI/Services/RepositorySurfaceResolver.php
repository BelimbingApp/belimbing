<?php

namespace App\Core\AI\Services;

use App\Base\AI\Tools\ToolArgumentException;
use App\Base\Foundation\ApplicationTopology;
use App\Core\AI\DTO\RepositorySurface;

class RepositorySurfaceResolver
{
    /**
     * Directory prefixes that belong to separately owned source repositories.
     *
     * @var list<string>
     */
    private const SOURCE_PREFIXES = [
        ApplicationTopology::DOMAINS.'/',
        ApplicationTopology::EXTENSIONS.'/',
    ];

    public function resolve(?string $targetSurface = null): RepositorySurface
    {
        $targetSurface = trim($targetSurface ?: 'platform');

        if ($targetSurface === 'platform' || $targetSurface === 'core') {
            return new RepositorySurface(
                target: 'platform',
                rootPath: base_path(),
                relativeRoot: '',
            );
        }

        if (str_starts_with($targetSurface, 'domain:')) {
            return $this->resolveOwnedSource(
                targetSurface: $targetSurface,
                prefix: 'domain:',
                relativeRoot: ApplicationTopology::DOMAINS,
            );
        }

        if (str_starts_with($targetSurface, 'extension:')) {
            return $this->resolveOwnedSource(
                targetSurface: $targetSurface,
                prefix: 'extension:',
                relativeRoot: ApplicationTopology::EXTENSIONS,
            );
        }

        throw new ToolArgumentException(
            'target_surface must be "platform", "domain:<slug>", or "extension:<slug>".'
        );
    }

    private function resolveOwnedSource(
        string $targetSurface,
        string $prefix,
        string $relativeRoot,
    ): RepositorySurface {
        $slug = substr($targetSurface, strlen($prefix));

        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            throw new ToolArgumentException('Repository source slug contains invalid characters.');
        }

        foreach (glob(base_path($relativeRoot).'/*', GLOB_ONLYDIR) ?: [] as $rootPath) {
            if (str(basename($rootPath))->kebab()->toString() !== str($slug)->kebab()->toString()) {
                continue;
            }

            $sourceRelativeRoot = $relativeRoot.'/'.basename($rootPath);

            return new RepositorySurface(
                target: $prefix.str($slug)->kebab()->toString(),
                rootPath: $rootPath,
                relativeRoot: $sourceRelativeRoot,
                domainSlug: $prefix === 'domain:' ? $slug : null,
                extensionSlug: $prefix === 'extension:' ? $slug : null,
            );
        }

        $label = rtrim($prefix, ':');

        throw new ToolArgumentException(ucfirst($label)." surface \"{$slug}\" was not found.");
    }

    public function resolvePath(string $filePath, ?string $targetSurface = null): string
    {
        $this->validateRelativePath($filePath);

        $surface = $this->resolve($targetSurface);
        $normalized = $this->normalizeRelativePath($filePath);

        if ($surface->isPlatform()) {
            $this->assertPlatformPath($normalized);

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
        $rawPath = $surface->rootPath.'/'.$relativePath;

        $realRoot = realpath($surface->rootPath);
        if ($realRoot === false) {
            return $rawPath;
        }

        $resolved = realpath($rawPath);
        if ($resolved !== false) {
            if (! str_starts_with($resolved, $realRoot.DIRECTORY_SEPARATOR) && $resolved !== $realRoot) {
                throw new ToolArgumentException('Resolved path escapes the target surface.');
            }

            return $resolved;
        }

        $realParent = realpath(dirname($rawPath));
        if ($realParent !== false && ! str_starts_with($realParent, $realRoot.DIRECTORY_SEPARATOR) && $realParent !== $realRoot) {
            throw new ToolArgumentException('Target directory escapes the target surface.');
        }

        return $rawPath;
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

    private function assertPlatformPath(string $filePath): void
    {
        foreach (self::SOURCE_PREFIXES as $prefix) {
            if (str_starts_with($filePath, $prefix)) {
                throw new ToolArgumentException(
                    'This path belongs to a separate repository. Select its domain or extension target_surface.'
                );
            }
        }
    }
}
