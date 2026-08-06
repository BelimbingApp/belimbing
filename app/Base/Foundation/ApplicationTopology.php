<?php

namespace App\Base\Foundation;

/**
 * Canonical filesystem contract for application-owned code.
 *
 * Keep topology knowledge here so discovery, lifecycle, tooling, and tests do
 * not independently reconstruct the four application roots.
 */
final class ApplicationTopology
{
    public const string BASE = 'app/Base';

    public const string CORE = 'app/Core';

    public const string DOMAINS = 'app/Domains';

    public const string EXTENSIONS = 'app/Extensions';

    /**
     * Canonical application roots as repository-relative paths.
     *
     * @return list<string>
     */
    public static function relativeRoots(): array
    {
        return [
            self::BASE,
            self::CORE,
            self::DOMAINS,
            self::EXTENSIONS,
        ];
    }

    public static function baseRoot(): string
    {
        return base_path(self::BASE);
    }

    public static function coreRoot(): string
    {
        return base_path(self::CORE);
    }

    public static function domainsRoot(): string
    {
        return base_path(self::DOMAINS);
    }

    public static function extensionsRoot(): string
    {
        return base_path(self::EXTENSIONS);
    }

    public static function domainPath(string $domain): string
    {
        return base_path(self::relativePathUnder(self::DOMAINS, $domain));
    }

    public static function extensionPath(string $extension): string
    {
        return base_path(self::relativePathUnder(self::EXTENSIONS, $extension));
    }

    /**
     * Identify the canonical relative root that owns an absolute or
     * repository-relative path.
     */
    public static function rootFor(string $path): ?string
    {
        $relative = ApplicationTopologyPath::repositoryRelativePath($path);

        if ($relative === null) {
            return null;
        }

        foreach (self::relativeRoots() as $root) {
            if ($relative === $root || str_starts_with($relative, $root.'/')) {
                return $root;
            }
        }

        return null;
    }

    public static function belongsToRoot(string $path, string $root): bool
    {
        self::assertRelativeRoot($root);

        return self::rootFor($path) === $root;
    }

    /**
     * Build a canonical repository-relative path below one application root.
     */
    public static function relativePathUnder(string $root, string ...$segments): string
    {
        self::assertRelativeRoot($root);

        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_contains($segment, '/')
                || str_contains($segment, '\\')) {
                throw new \InvalidArgumentException(sprintf(
                    'Application topology path segment [%s] must be one non-traversing segment.',
                    $segment,
                ));
            }
        }

        return implode('/', [$root, ...$segments]);
    }

    public static function baseComponentPattern(string $artifact = ''): string
    {
        return ApplicationTopologyPath::pattern(self::baseRoot(), '*', $artifact);
    }

    public static function coreModulePattern(string $artifact = ''): string
    {
        return ApplicationTopologyPath::pattern(self::coreRoot(), '*', $artifact);
    }

    public static function domainPattern(string $artifact = ''): string
    {
        return ApplicationTopologyPath::pattern(self::domainsRoot(), '*', $artifact);
    }

    public static function domainModulePattern(string $artifact = ''): string
    {
        return ApplicationTopologyPath::pattern(self::domainsRoot(), '*', '*', $artifact);
    }

    public static function extensionSourcePattern(string $artifact = ''): string
    {
        return ApplicationTopologyPath::pattern(self::extensionsRoot(), '*', $artifact);
    }

    public static function extensionModulePattern(string $artifact = ''): string
    {
        return ApplicationTopologyPath::pattern(self::extensionsRoot(), '*', '*', $artifact);
    }

    /**
     * All application contribution patterns in deterministic runtime order.
     *
     * @return list<string>
     */
    public static function contributionPatterns(
        string $artifact = '',
        bool $includeExtensionSource = false,
    ): array {
        $patterns = [
            self::baseComponentPattern($artifact),
            self::coreModulePattern($artifact),
            self::domainModulePattern($artifact),
        ];

        if ($includeExtensionSource) {
            $patterns[] = self::extensionSourcePattern($artifact);
        }

        $patterns[] = self::extensionModulePattern($artifact);

        return $patterns;
    }

    private static function assertRelativeRoot(string $root): void
    {
        if (! in_array($root, self::relativeRoots(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown application topology root [%s].',
                $root,
            ));
        }
    }
}
