#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Removes the standard BLB legal header from source files:
 * one blank line (when present) plus SPDX + copyright comment lines.
 *
 * Handles // (PHP, Blade, etc.) and # (shell) comment styles, including when
 * there is no empty line between <?php (or the shebang) and the SPDX line, and
 * an optional UTF-8 BOM at the start of the file.
 *
 * Usage: php scripts/remove-legal-header-comments.php
 *
 * Only files whose SPDX + copyright lines appear within the first few lines are
 * considered; the rest are skipped without reading the whole file.
 */

/**
 * Reads at most the first $lineCount lines (including line terminators) for a cheap match probe.
 */
function readFirstLines(string $path, int $lineCount): ?string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    $buffer = '';
    for ($i = 0; $i < $lineCount; $i++) {
        $line = fgets($handle);
        if ($line === false) {
            break;
        }
        $buffer .= $line;
    }

    fclose($handle);

    return $buffer;
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

function detectEol(string $content): string
{
    return str_contains($content, "\r\n") ? "\r\n" : "\n";
}

function stripLegalHeaders(string $content, string $spdxLine, string $copyrightNeedle): string
{
    $eol = detectEol($content);

    $phpSpdx = preg_quote('// '.$spdxLine, '/');
    $phpCopy = preg_quote('// '.$copyrightNeedle, '/').'[^\r\n]*';
    $hashSpdx = preg_quote('# '.$spdxLine, '/');
    $hashCopy = preg_quote('# '.$copyrightNeedle, '/').'[^\r\n]*';

    // Blade/comment-only PHP preamble: remove the entire wrapper block and one trailing blank line.
    $out = preg_replace(
        '/\A(\xEF\xBB\xBF)?<\?php[ \t]*\R(?:\R)?'.$phpSpdx.'\R'.$phpCopy.'\R\?>[ \t]*(?:\R){1,2}/u',
        '$1',
        $content
    ) ?? $content;

    if ($out !== $content) {
        return $out;
    }

    // PHP preamble at file start: keep <?php, remove only the leading legal header block.
    $out = preg_replace(
        '/\A(\xEF\xBB\xBF)?<\?php[ \t]*\R(?:\R)?'.$phpSpdx.'\R'.$phpCopy.'\R(?:\R)?/u',
        '$1<?php'.$eol,
        $content
    ) ?? $content;

    if ($out !== $content) {
        return $out;
    }

    // Shell: keep the shebang, remove the leading legal header block after it.
    $out = preg_replace(
        '/\A(\xEF\xBB\xBF)?(#![^\r\n]*)\R(?:\R)?'.$hashSpdx.'\R'.$hashCopy.'\R(?:\R)?/u',
        '$1$2'.$eol,
        $out
    ) ?? $out;

    return $out;
}

/**
 * @param  list<string>  $skipDirBasenames
 * @return RecursiveIteratorIterator<int, SplFileInfo>
 */
function legalHeaderSourceFileIterator(string $root, array $skipDirBasenames): RecursiveIteratorIterator
{
    return new RecursiveIteratorIterator(
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
}

/**
 * @param  list<string>  $extensions
 */
function tryStripLegalHeaderFromFile(
    string $path,
    string $spdxLine,
    string $copyrightNeedle,
    int $headerPeekLineCount,
    array $extensions,
): bool {
    if (! shouldProcessFile($path, $extensions)) {
        return false;
    }

    $prefix = readFirstLines($path, $headerPeekLineCount);
    if ($prefix === null || $prefix === '') {
        return false;
    }

    if (! str_contains($prefix, $spdxLine) || ! str_contains($prefix, $copyrightNeedle)) {
        return false;
    }

    $original = file_get_contents($path);
    if ($original === false || $original === '') {
        return false;
    }

    $updated = stripLegalHeaders($original, $spdxLine, $copyrightNeedle);
    if ($updated === $original) {
        return false;
    }

    if (file_put_contents($path, $updated) === false) {
        fwrite(STDERR, "Failed to write: {$path}\n");

        return false;
    }

    return true;
}

function runRemoveLegalHeaderComments(): void
{
    $repoRoot = dirname(__DIR__);

    $headerPeekLineCount = 10;

    $relativeRoots = ['app', 'resources', 'extensions', 'scripts'];
    $skipDirBasenames = ['.git', 'node_modules', 'vendor', '.svn'];

    $spdxLine = 'SPDX-License-Identifier: AGPL-3.0-only';
    $copyrightNeedle = '(c) Ng Kiat Siong';

    $extensions = [
        'php', 'blade.php', 'css', 'scss', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue',
        'sh', 'bash', 'ps1', 'json', 'md', 'yaml', 'yml', 'xml', 'html', 'txt', 'stub',
    ];

    $changedFiles = 0;

    foreach ($relativeRoots as $rel) {
        $root = $repoRoot.DIRECTORY_SEPARATOR.$rel;
        if (! is_dir($root)) {
            fwrite(STDERR, "Skip missing directory: {$rel}\n");

            continue;
        }

        $iterator = legalHeaderSourceFileIterator($root, $skipDirBasenames);

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (tryStripLegalHeaderFromFile($path, $spdxLine, $copyrightNeedle, $headerPeekLineCount, $extensions)) {
                $changedFiles++;
            }
        }
    }

    echo "Updated {$changedFiles} file(s).\n";
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && @realpath($_SERVER['argv'][0]) === @realpath(__FILE__)) {
    runRemoveLegalHeaderComments();
}
