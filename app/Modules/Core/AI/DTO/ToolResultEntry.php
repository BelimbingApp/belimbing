<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

final readonly class ToolResultEntry
{
    /**
     * @param  array<string, mixed>|null  $errorPayload
     */
    public function __construct(
        public string $toolName,
        public string $resultPreview,
        public int $resultLength,
        public string $status,
        public int $durationMs,
        public ?array $errorPayload = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        $meta = [
            'tool' => $this->toolName,
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
