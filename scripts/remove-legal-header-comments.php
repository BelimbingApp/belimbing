#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Removes the standard BLB legal header from source files:
 * one blank line (when present) plus SPDX + copyright comment lines.
 *
 * Handles // (PHP, Blade, etc.) and # (shell) comment styles.
 *
 * Usage:
 *   php scripts/remove-legal-header-comments.php           # apply changes
 *   php scripts/remove-legal-header-comments.php --dry-run # print paths only
 */
$dryRun = in_array('--dry-run', $argv, true);
$repoRoot = dirname(__DIR__);

$relativeRoots = ['app', 'resources', 'extensions', 'scripts'];
$skipDirBasenames = ['.git', 'node_modules', 'vendor', '.svn'];

$spdxLine = 'SPDX-License-Identifier: AGPL-3.0-only';
$copyrightNeedle = '(c) Ng Kiat Siong';

$extensions = [
    'php', 'blade.php', 'css', 'scss', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue',
    'sh', 'bash', 'ps1', 'json', 'md', 'yaml', 'yml', 'xml', 'html', 'txt', 'stub',
];

$buildPatterns = static function (string $lead) use ($spdxLine, $copyrightNeedle): array {
    $spdx = preg_quote($lead.$spdxLine, '/');
    $copy = preg_quote($lead.$copyrightNeedle, '/').'[^\r\n]*';

    // Blank line before SPDX + two comment lines (trailing newline after copyright)
    $withBlankBefore = '/\r?\n\r?\n'.$spdx."\r?\n".$copy."\r?\n/u";

    // Same block without a blank line before SPDX (e.g. <?php then SPDX; shebang then SPDX)
    $withoutBlankBefore = '/\r?\n'.$spdx."\r?\n".$copy."\r?\n/u";

    return [$withBlankBefore, $withoutBlankBefore];
};

/** @var array<int, array{0: string, 1: string}> */
$patternGroups = [
    $buildPatterns('// '),
    $buildPatterns('# '),
];

$changedFiles = 0;

foreach ($relativeRoots as $rel) {
    $root = $repoRoot.DIRECTORY_SEPARATOR.$rel;
    if (! is_dir($root)) {
        fwrite(STDERR, "Skip missing directory: {$rel}\n");

        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($skipDirBasenames): bool {
                if (! $current->isDir()) {
                    return true;
                }

                return ! in_array($current->getBasename(), $skipDirBasenames, true);
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        if (! shouldProcessFile($path, $extensions)) {
            continue;
        }

        $original = file_get_contents($path);
        if ($original === false || $original === '') {
            continue;
        }

        if (! str_contains($original, $spdxLine) || ! str_contains($original, $copyrightNeedle)) {
            continue;
        }

        $updated = stripLegalHeaders($original, $patternGroups);
        if ($updated === $original) {
            continue;
        }

        if ($dryRun) {
            echo $path."\n";
            $changedFiles++;

            continue;
        }

        if (file_put_contents($path, $updated) !== false) {
            $changedFiles++;
        } else {
            fwrite(STDERR, "Failed to write: {$path}\n");
        }
    }
}

if ($dryRun) {
    echo "Dry run: {$changedFiles} file(s) would be modified.\n";
} else {
    echo "Updated {$changedFiles} file(s).\n";
}

/**
 * @param  list<string>  $extensions
 */
function shouldProcessFile(string $path, array $extensions): bool
{
    $lower = strtolower($path);
    foreach ($extensions as $ext) {
        $suffix = '.'.$ext;
        if (str_ends_with($lower, $suffix)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<int, array{0: string, 1: string}>  $patternGroups
 */
function stripLegalHeaders(string $content, array $patternGroups): string
{
    $prev = null;
    $out = $content;
    $guard = 0;
    while ($out !== $prev && $guard < 32) {
        $prev = $out;
        foreach ($patternGroups as [$withBlank, $withoutBlank]) {
            $out = preg_replace($withBlank, "\n", $out) ?? $out;
            $out = preg_replace($withoutBlank, "\n", $out) ?? $out;
        }
        $guard++;
    }

    return $out;
}
