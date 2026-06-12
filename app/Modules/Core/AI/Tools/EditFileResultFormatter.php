<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Tools\ToolResult;

final class EditFileResultFormatter
{
    private const MAX_DIFF_PREVIEW_BYTES = 12000;

    /**
     * @param  array{
     *     target_surface: string,
     *     file_path: string,
     *     operation: string,
     *     summary: string,
     *     bytes_written: int,
     *     before: string|null,
     *     after: string,
     *     created: bool,
     *     changed: bool,
     *     replacement_count?: int,
     *     diff_body?: string
     * }  $details
     */
    public static function format(array $details): ToolResult
    {
        [$diffPreview, $diffTruncated] = self::cappedDiffPreview($details);

        $payload = [
            'target_surface' => $details['target_surface'],
            'file_path' => $details['file_path'],
            'operation' => $details['operation'],
            'created' => $details['created'],
            'changed' => $details['changed'],
            'bytes_written' => $details['bytes_written'],
            'summary' => $details['summary'],
            'diff_preview' => $diffPreview,
            'diff_truncated' => $diffTruncated,
        ];

        if (isset($details['replacement_count'])) {
            $payload['replacement_count'] = $details['replacement_count'];
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return ToolResult::success($details['summary']);
        }

        return ToolResult::success($encoded);
    }

    public static function replacementPreview(string $oldContent, string $newContent): string
    {
        return "@@ targeted replacement @@\n"
            .self::prefixLines('-', $oldContent)
            .self::prefixLines('+', $newContent);
    }

    public static function addedLinesPreview(string $content): string
    {
        return "@@ added content @@\n".self::prefixLines('+', $content);
    }

    /**
     * @param  array{file_path: string, before: string|null, after: string, diff_body?: string}  $details
     * @return array{0: string, 1: bool}
     */
    private static function cappedDiffPreview(array $details): array
    {
        $body = $details['diff_body'] ?? (
            $details['before'] === null
                ? self::addedLinesPreview($details['after'])
                : self::beforeAfterPreview($details['before'], $details['after'])
        );
        $preview = "--- before/{$details['file_path']}\n+++ after/{$details['file_path']}\n".$body;

        if (strlen($preview) <= self::MAX_DIFF_PREVIEW_BYTES) {
            return [$preview, false];
        }

        return [substr($preview, 0, self::MAX_DIFF_PREVIEW_BYTES)."\n... diff preview truncated ...", true];
    }

    private static function beforeAfterPreview(string $before, string $after): string
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
