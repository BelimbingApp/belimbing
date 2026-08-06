<?php

namespace App\Base\Foundation;

/**
 * Internal path-normalization utilities for ApplicationTopology.
 *
 * Separated from the topology contract so canonical root knowledge remains the
 * sole public concern of ApplicationTopology. These helpers are generic path
 * math — they carry no topology semantics.
 */
final class ApplicationTopologyPath
{
    public static function pattern(string ...$segments): string
    {
        $artifact = array_pop($segments);
        $path = self::join(...$segments);

        return $artifact === '' ? $path : self::join($path, $artifact);
    }

    public static function join(string ...$segments): string
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

    public static function repositoryRelativePath(string $path): ?string
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

    public static function normalizePath(string $path): ?string
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

    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    public static function samePath(string $left, string $right): bool
    {
        return self::usesWindowsDrive($left) && self::usesWindowsDrive($right)
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    public static function pathStartsWith(string $path, string $prefix): bool
    {
        return self::usesWindowsDrive($path) && self::usesWindowsDrive($prefix)
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);
    }

    public static function usesWindowsDrive(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\//', $path) === 1;
    }
}
