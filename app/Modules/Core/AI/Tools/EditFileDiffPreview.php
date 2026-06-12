<?php

namespace App\Modules\Core\AI\Tools;

class EditFileDiffPreview
{
    public const MAX_BYTES = 12000;

    /**
     * @return array{0: string, 1: bool}
     */
    public static function capped(string $filePath, ?string $before, string $after, ?string $diffBody): array
    {
        $body = $diffBody ?? ($before === null
            ? self::addedLines($after)
            : self::beforeAfter($before, $after));
        $preview = "--- before/{$filePath}\n+++ after/{$filePath}\n".$body;

        if (strlen($preview) <= self::MAX_BYTES) {
            return [$preview, false];
        }

        return [substr($preview, 0, self::MAX_BYTES)."\n... diff preview truncated ...", true];
    }

    public static function replacement(string $oldContent, string $newContent): string
    {
        return "@@ targeted replacement @@\n"
            .self::prefixLines('-', $oldContent)
            .self::prefixLines('+', $newContent);
    }

    public static function addedLines(string $content): string
    {
        return "@@ added content @@\n".self::prefixLines('+', $content);
    }

    private static function beforeAfter(string $before, string $after): string
    {
        return "@@ previous content @@\n"
            .self::prefixLines('-', $before)
            ."@@ new content @@\n"
            .self::prefixLines('+', $after);
    }

    private static function prefixLines(string $prefix, string $content): string
    {
        if ($content === '') {
            return $prefix."\n";
        }

        return collect(preg_split('/\R/', $content) ?: [])
            ->map(fn (string $line): string => $prefix.$line)
            ->implode("\n")."\n";
    }
}
