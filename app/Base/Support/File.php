<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Support;

final class File
{
    /**
     * Ensure a directory exists.
     */
    public static function ensureDirectory(string $directory, int $permissions = 0755): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, $permissions, true);
        }
    }

    /**
     * Write a file, creating parent directories when missing.
     */
    public static function put(string $path, string $contents, int $flags = 0): int|false
    {
        self::ensureDirectory(dirname($path));

        return file_put_contents($path, $contents, $flags);
    }
}
