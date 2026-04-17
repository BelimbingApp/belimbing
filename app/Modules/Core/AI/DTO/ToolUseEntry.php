<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

final readonly class ToolUseEntry
{
    /**
     * @param  string  $toolName  Tool name identifier
     * @param  string  $argsSummary  Truncated tool call arguments (≤200 chars)
     * @param  int  $toolCallIndex  Sequential index within the run
     * @param  string  $resultPreview  Truncated result preview
     * @param  int  $resultLength  Full result length in bytes
     * @param  string  $status  Execution status (success, error, etc.)
     * @param  int  $durationMs  Execution duration in milliseconds
     * @param  array<string, mixed>|null  $errorPayload  Error details when status is not success
     */
    public function __construct(
        public string $toolName,
        public string $argsSummary = '{}',
        public int $toolCallIndex = 0,
        public string $resultPreview = '',
        public int $resultLength = 0,
        public string $status = 'success',
        public int $durationMs = 0,
        public ?array $errorPayload = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        $meta = [
            'tool' => $this->toolName,
            'args_summary' => $this->argsSummary,
            'tool_call_index' => $this->toolCallIndex,
            'result_preview' => $this->resultPreview,
            'result_length' => $this->resultLength,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
        ];

        if ($this->errorPayload !== null) {
            $meta['error_payload'] = $this->errorPayload;
        }

        return $meta;
    }
}
