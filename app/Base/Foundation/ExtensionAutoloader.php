<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation;

use App\Base\Support\Str;

/**
 * Auto-discovers and loads classes under the Extensions\ namespace.
 *
 * Maps PascalCase namespace segments to kebab-case directory names
 * for the first two levels (owner and module), then uses standard
 * PSR-4 resolution for the rest.
 *
 * Example: Extensions\SbGroup\Qac\Services\SbgNumberingService
 *        → extensions/sb-group/qac/Services/SbgNumberingService.php
 */
final class ExtensionAutoloader
{
    private static bool $registered = false;

    /**
     * Register the extension autoloader.
     *
     * Safe to call multiple times; only registers once.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(self::load(...));
        self::$registered = true;
    }

    /**
     * Attempt to load a class under the Extensions\ namespace.
     */
    public static function load(string $class): void
    {
        if (! str_starts_with($class, 'Extensions\\')) {
            return;
        }

        $segments = explode('\\', $class);

        // Minimum: Extensions\Owner\Module\Class (4 segments)
        if (count($segments) < 4) {
            return;
        }

        // segments[0] = "Extensions" → extensions/
        // segments[1] = "SbGroup"    → sb-group/    (PascalCase → kebab-case)
        // segments[2] = "Qac"        → qac/         (PascalCase → kebab-case)
        // segments[3..n]             → direct PSR-4  (unchanged)
        $owner = Str::pascalToKebab($segments[1]);
        $module = Str::pascalToKebab($segments[2]);
        $remaining = array_slice($segments, 3);

        $basePath = dirname(__DIR__, 3);

        $file = $basePath.'/extensions/'.$owner.'/'.$module.'/'.implode('/', $remaining).'.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
