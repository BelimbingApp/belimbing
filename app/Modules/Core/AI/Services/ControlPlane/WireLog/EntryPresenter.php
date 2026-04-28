<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

/**
 * @internal
 *
 * Normalizes non-stream wire-log entries into a readable UI representation.
 */
final class EntryPresenter
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function buildEvent(array $entry): array
    {
        $type = is_string($entry['type'] ?? null) ? $entry['type'] : 'unknown';
        $decoded = is_array($entry['decoded_payload'] ?? null) ? $entry['decoded_payload'] : null;
        $previewStatus = is_string($entry['preview_status'] ?? null) ? $entry['preview_status'] : 'full';

        return [
            'kind' => 'event',
            'entry_number' => (int) ($entry['entry_number'] ?? 0),
            'at' => is_string($entry['at'] ?? null) ? $entry['at'] : null,
            'type' => $type,
            'label' => $this->eventLabel($type),
            'severity' => $this->eventSeverity($type, $decoded, $previewStatus),
            'summary' => $this->eventSummary($type, $decoded, $entry),
            'details' => $this->buildEventDetails($type, $decoded),
            'preview_status' => $previewStatus,
            'payload_pretty' => is_string($entry['payload_pretty'] ?? null) ? (string) $entry['payload_pretty'] : '',
        ];
    }

    private function eventLabel(string $type): string
    {
        return match ($type) {
            'llm.request' => __('Request'),
            'llm.response_status' => __('Response Status'),
            'llm.response_body' => __('Response Body'),
            'llm.first_byte' => __('First Byte'),
            'llm.error' => __('Error'),
            'llm.complete' => __('Complete'),
            default => $type,
        };
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function eventSeverity(string $type, ?array $decoded, string $previewStatus): string
    {
        if ($previewStatus === 'decode_error' || $previewStatus === 'encode_error') {
            return 'danger';
        }

        if ($type === 'llm.error') {
            return 'danger';
        }

        if ($type === 'llm.response_status' && is_array($decoded)) {
            $status = (int) ($decoded['status_code'] ?? 0);

            if ($status > 0 && ($status < 200 || $status >= 300)) {
                return 'danger';
            }

            return 'success';
        }

        if ($type === 'llm.complete') {
            return 'success';
        }

        return 'default';
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     * @param  array<string, mixed>  $entry
     */
    private function eventSummary(string $type, ?array $decoded, array $entry): string
    {
        return match ($type) {
            'llm.request' => $this->requestSummary($decoded),
            'llm.response_status' => __(':status response received', [
                'status' => is_array($decoded) ? (string) ($decoded['status_code'] ?? '?') : '?',
            ]),
            'llm.response_body' => $this->responseBodySummary($decoded),
            'llm.first_byte' => __('First byte arrived'),
            'llm.error' => is_array($decoded) ? (string) ($decoded['message'] ?? __('Transport error')) : __('Transport error'),
            'llm.complete' => __('Transport completed'),
            default => is_string($entry['summary_preview'] ?? null) ? (string) $entry['summary_preview'] : '',
        };
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function requestSummary(?array $decoded): string
    {
        if ($decoded === null) {
            return __('Outbound request');
        }

        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
        $tools = is_array($request['tools'] ?? null) ? $request['tools'] : [];
        $model = is_string($request['model'] ?? null) ? $request['model'] : null;
        $model ??= is_string($decoded['model'] ?? null) ? $decoded['model'] : '?';
        $provider = is_string($request['provider_name'] ?? null) ? $request['provider_name'] : '?';

        return __(':provider / :model — :messages messages, :tools tools', [
            'provider' => $provider,
            'model' => $model,
            'messages' => count($messages),
            'tools' => count($tools),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function responseBodySummary(?array $decoded): string
    {
        if ($decoded === null) {
            return __('Response body');
        }

        $rawBody = is_string($decoded['raw_body'] ?? null) ? $decoded['raw_body'] : '';
        $bytes = strlen($rawBody);
        $statusCode = is_int($decoded['status_code'] ?? null) ? (int) $decoded['status_code'] : null;

        return __(':status, :bytes bytes', [
            'status' => $statusCode ?? '?',
            'bytes' => number_format($bytes),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     * @return array<string, mixed>
     */
    private function buildEventDetails(string $type, ?array $decoded): array
    {
        if ($decoded === null) {
            return [];
        }

        return match ($type) {
            'llm.request' => $this->requestDetails($decoded),
            'llm.response_status' => [
                'status_code' => (int) ($decoded['status_code'] ?? 0),
                'stream' => (bool) ($decoded['stream'] ?? false),
            ],
            'llm.response_body' => [
                'status_code' => (int) ($decoded['status_code'] ?? 0),
                'bytes' => is_string($decoded['raw_body'] ?? null) ? strlen($decoded['raw_body']) : 0,
                'has_decoded' => is_array($decoded['decoded_body'] ?? null),
            ],
            'llm.error' => [
                'stage' => is_string($decoded['stage'] ?? null) ? $decoded['stage'] : null,
                'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : null,
            ],
            'llm.complete' => [
                'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : null,
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function requestDetails(array $decoded): array
    {
        $request = is_array($decoded['request'] ?? null) ? $decoded['request'] : [];
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
        $tools = is_array($request['tools'] ?? null) ? $request['tools'] : [];

        return [
            'endpoint' => is_string($decoded['endpoint'] ?? null) ? $decoded['endpoint'] : null,
            'stream' => (bool) ($decoded['stream'] ?? false),
            'provider' => is_string($request['provider_name'] ?? null) ? $request['provider_name'] : null,
            'model' => is_string($request['model'] ?? null) ? $request['model'] : null,
            'message_count' => count($messages),
            'tool_count' => count($tools),
            'timeout_seconds' => $request['timeout'] ?? null,
        ];
    }
}
