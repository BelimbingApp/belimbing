<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Support\Json as BlbJson;
use Illuminate\Support\Str;

final class WireLogEntryPreviewer
{
    private const PREVIEW_LINE_BYTES = 64 * 1024;

    private const PREVIEW_PAYLOAD_BYTES = 24 * 1024;

    /**
     * @return array{
     *     entry_number: int,
     *     at: string|null,
     *     type: string|null,
     *     summary_preview: string,
     *     payload_pretty: string,
     *     payload_truncated: bool,
     *     preview_status: string,
     *     raw_line: string,
     *     decoded_payload: array<string, mixed>|null
     * }
     */
    public function previewEntry(int $entryNumber, string $line, bool $lineTruncated): array
    {
        $at = $this->extractScalar($line, 'at');
        $type = $this->extractScalar($line, 'type');
        $payload = $this->previewPayload($line, $lineTruncated, $at, $type);

        return [
            'entry_number' => $entryNumber,
            'at' => $payload['at'],
            'type' => $payload['type'],
            'summary_preview' => $payload['summary_preview'],
            'payload_pretty' => $payload['payload_pretty'],
            'payload_truncated' => $payload['payload_truncated'],
            'preview_status' => $payload['preview_status'],
            'raw_line' => $line,
            'decoded_payload' => $payload['decoded_payload'],
        ];
    }

    /**
     * @return array{
     *     at: string|null,
     *     type: string|null,
     *     summary_preview: string,
     *     payload_pretty: string,
     *     payload_truncated: bool,
     *     preview_status: string,
     *     decoded_payload: array<string, mixed>|null
     * }
     */
    private function previewPayload(string $line, bool $lineTruncated, ?string $fallbackAt, ?string $fallbackType): array
    {
        $payload = [
            'at' => $fallbackAt,
            'type' => $fallbackType,
            'summary_preview' => $this->summaryPreviewFromRawLine($line),
            'payload_pretty' => __('Payload preview omitted because this wire-log entry exceeds :size.', [
                'size' => number_format(self::PREVIEW_LINE_BYTES / 1024).' KB',
            ]),
            'payload_truncated' => $lineTruncated,
            'preview_status' => 'line_omitted',
            'decoded_payload' => null,
        ];

        if ($lineTruncated) {
            return $payload;
        }

        $decoded = BlbJson::decodeArray($line);
        $payload['decoded_payload'] = $decoded;

        if ($decoded === null) {
            return array_merge($payload, [
                'payload_pretty' => __('Payload preview unavailable because this wire-log entry could not be decoded.'),
                'payload_truncated' => true,
                'preview_status' => 'decode_error',
            ]);
        }

        return $this->previewDecodedPayload($decoded, $payload);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function previewDecodedPayload(array $decoded, array $payload): array
    {
        $payload['at'] = is_string($decoded['at'] ?? null) ? $decoded['at'] : $payload['at'];
        $payload['type'] = is_string($decoded['type'] ?? null) ? $decoded['type'] : $payload['type'];

        unset($decoded['at'], $decoded['type']);

        $payload['summary_preview'] = $this->summaryPreviewFromPayload($payload['type'], $decoded);
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            return array_merge($payload, [
                'payload_pretty' => __('Payload preview unavailable because this wire-log entry could not be encoded.'),
                'payload_truncated' => true,
                'preview_status' => 'encode_error',
            ]);
        }

        $payload['payload_pretty'] = $encoded;
        $payload['payload_truncated'] = strlen($encoded) > self::PREVIEW_PAYLOAD_BYTES;
        $payload['preview_status'] = $payload['payload_truncated'] ? 'payload_truncated' : 'full';

        if ($payload['payload_truncated']) {
            $payload['payload_pretty'] = substr($encoded, 0, self::PREVIEW_PAYLOAD_BYTES)."\n…";
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryPreviewFromPayload(?string $type, array $payload): string
    {
        if ($type === 'llm.stream_line') {
            return $this->streamLineSummaryPreview($payload);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return Str::limit($encoded, 120, '...');
    }

    private function summaryPreviewFromRawLine(string $line): string
    {
        $summary = preg_replace('/^{"at":"[^"]*","type":"[^"]*",?/', '{', $line);
        $summary = is_string($summary) ? $summary : $line;

        return Str::limit($summary, 120, '...');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function streamLineSummaryPreview(array $payload): string
    {
        $rawLine = is_string($payload['raw_line'] ?? null) ? $payload['raw_line'] : '';

        if ($rawLine === '') {
            return '[]';
        }

        if ($rawLine === 'data: [DONE]') {
            return 'finish_reason: [DONE]';
        }

        $summary = str_starts_with($rawLine, 'data: ')
            ? $this->summaryFromDataLine(substr($rawLine, 6))
            : null;

        return $summary ?? Str::limit($rawLine, 120, '...');
    }

    private function summaryFromDataLine(string $line): ?string
    {
        $data = BlbJson::decodeArray($line);

        return is_array($data) ? $this->streamChunkSemanticSummary($data) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function streamChunkSemanticSummary(array $data): ?string
    {
        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice)) {
            return null;
        }

        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        return $this->summaryFromTextDelta($delta)
            ?? $this->summaryFromToolCallDelta($delta)
            ?? $this->summaryFromFinishReason($choice['finish_reason'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    private function summaryFromTextDelta(array $delta): ?string
    {
        if (is_string($delta['reasoning_content'] ?? null) && $delta['reasoning_content'] !== '') {
            return 'reasoning_content: '.$this->quotedPreview($delta['reasoning_content']);
        }

        if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            return 'content: '.$this->quotedPreview($delta['content']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    private function summaryFromToolCallDelta(array $delta): ?string
    {
        $toolCallDeltas = $delta['tool_calls'] ?? null;

        if (! is_array($toolCallDeltas) || ! isset($toolCallDeltas[0]) || ! is_array($toolCallDeltas[0])) {
            return null;
        }

        $toolCall = $toolCallDeltas[0];
        $toolName = $toolCall['function']['name'] ?? null;
        $argumentsDelta = $toolCall['function']['arguments'] ?? null;

        if (is_string($toolName) && $toolName !== '') {
            return 'tool_call: '.$toolName;
        }

        return is_string($argumentsDelta) && $argumentsDelta !== ''
            ? 'tool_args: '.$this->quotedPreview($argumentsDelta)
            : null;
    }

    private function summaryFromFinishReason(mixed $finishReason): ?string
    {
        return is_string($finishReason) && $finishReason !== ''
            ? 'finish_reason: '.$finishReason
            : null;
    }

    private function quotedPreview(string $value): string
    {
        return '"'.Str::limit($value, 72, '...').'"';
    }

    private function extractScalar(string $line, string $key): ?string
    {
        if (! preg_match('/"'.preg_quote($key, '/').'":"((?:[^"\\\\]|\\\\.)*)"/', $line, $matches)) {
            return null;
        }

        $decoded = json_decode('"'.$matches[1].'"');

        return is_string($decoded) ? $decoded : null;
    }
}
