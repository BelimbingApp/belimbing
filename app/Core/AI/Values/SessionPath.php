<?php

namespace App\Core\AI\Values;

use App\Core\AI\Exceptions\SessionPathContainmentException;

/**
 * Resolve validated session paths beneath an explicitly authorized root.
 */
final class SessionPath
{
    public static function meta(string $sessionRoot, SessionId $sessionId): string
    {
        return self::contained($sessionRoot, [$sessionId->value.'.meta.json']);
    }

    public static function transcript(string $sessionRoot, SessionId $sessionId): string
    {
        return self::contained($sessionRoot, [$sessionId->value.'.jsonl']);
    }

    public static function attachments(string $sessionRoot, SessionId $sessionId): string
    {
        return self::contained($sessionRoot, ['attachments', $sessionId->value]);
    }

    /**
     * @param  non-empty-list<string>  $segments
     */
    private static function contained(string $sessionRoot, array $segments): string
    {
        $root = self::canonicalize($sessionRoot);
        $candidate = self::canonicalize($root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments));
        $comparableRoot = self::comparable($root);
        $comparableCandidate = self::comparable($candidate);

        if (! str_starts_with($comparableCandidate, $comparableRoot.DIRECTORY_SEPARATOR)) {
            throw new SessionPathContainmentException;
        }

        return $candidate;
    }

    /**
     * Resolve existing symlinks while retaining safe, not-yet-created path segments.
     */
    private static function canonicalize(string $path): string
    {
        $cursor = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $unresolved = [];

        while (($resolved = realpath($cursor)) === false) {
            if (is_link($cursor)) {
                throw new SessionPathContainmentException;
            }

            $parent = dirname($cursor);

            if ($parent === $cursor) {
                throw new SessionPathContainmentException;
            }

            array_unshift($unresolved, basename($cursor));
            $cursor = $parent;
        }

        foreach ($unresolved as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $resolved = $segment === '..'
                ? dirname($resolved)
                : rtrim($resolved, '/\\').DIRECTORY_SEPARATOR.$segment;
        }

        return $resolved;
    }

    private static function comparable(string $path): string
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
