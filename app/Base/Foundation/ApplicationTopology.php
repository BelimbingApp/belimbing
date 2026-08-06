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
        $relative = self::repositoryRelativePath($path);

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
        return self::pattern(self::baseRoot(), '*', $artifact);
    }

    public static function coreModulePattern(string $artifact = ''): string
    {
        return self::pattern(self::coreRoot(), '*', $artifact);
    }

    public static function domainPattern(string $artifact = ''): string
    {
        return self::pattern(self::domainsRoot(), '*', $artifact);
    }

    public static function domainModulePattern(string $artifact = ''): string
    {
        return self::pattern(self::domainsRoot(), '*', '*', $artifact);
    }

    public static function extensionSourcePattern(string $artifact = ''): string
    {
        return self::pattern(self::extensionsRoot(), '*', $artifact);
    }

    public static function extensionModulePattern(string $artifact = ''): string
    {
        return self::pattern(self::extensionsRoot(), '*', '*', $artifact);
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

    private static function pattern(string ...$segments): string
    {
        $artifact = array_pop($segments);
        $path = self::join(...$segments);

        return $artifact === '' ? $path : self::join($path, $artifact);
    }

    private static function join(string ...$segments): string
    {
        $first = array_shift($segments);

        if ($first === null) {
            return '';
        }

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($first, '/\\'),
            ...array_map(
                static fn (string $segment): string => trim($segment, '/\\'),
                $segments,
            ),
        ]);
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

    private static function repositoryRelativePath(string $path): ?string
    {
        $normalized = self::normalizePath($path);

        if ($normalized === null) {
            return null;
        }

        $base = self::normalizePath(base_path());

        if ($base === null) {
            return null;
        }

        if (self::samePath($normalized, $base)) {
            return '';
        }

        $basePrefix = $base.'/';
        if (self::pathStartsWith($normalized, $basePrefix)) {
            return substr($normalized, strlen($basePrefix));
        }

        if (self::isAbsolutePath($normalized)) {
            return null;
        }

        return ltrim($normalized, '/');
    }

    private static function normalizePath(string $path): ?string
    {
        $normalized = rtrim(str_replace('\\', '/', trim($path)), '/');

        if ($normalized === '') {
            return '';
        }

        $segments = explode('/', $normalized);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return $normalized;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private static function samePath(string $left, string $right): bool
    {
        return self::usesWindowsDrive($left) && self::usesWindowsDrive($right)
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private static function pathStartsWith(string $path, string $prefix): bool
    {
        return self::usesWindowsDrive($path) && self::usesWindowsDrive($prefix)
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);
    }

    private static function usesWindowsDrive(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\//', $path) === 1;
    }
}
