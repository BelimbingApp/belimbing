<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Support;

final class AppPath
{
    /**
     * Convert an absolute PHP file path under app/ into an App\ FQCN.
     *
     * Returns null when the path is outside app/ so callers can decide whether
     * to skip it or fail fast.
     */
    public static function toClass(string $path): ?string
    {
        $normalizedPath = self::normalizeSeparators($path);
        $appPath = rtrim(self::normalizeSeparators(app_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($normalizedPath, $appPath)) {
            return null;
        }

        $relativePath = substr($normalizedPath, strlen($appPath));

        return 'App\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relativePath
        );
    }

    /**
     * Normalize path separators so support code works across slash styles.
     */
    private static function normalizeSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
