<?php

namespace App\Modules\Core\AI\DTO;

/**
 * Structured payload for a completed tool invocation event.
 */
final readonly class ToolFinishedPayload
{
    /**
     * @param  array<string, mixed>|null  $errorPayload
     */
    public function __construct(
        public string $status,
        public ?string $resultPreview = null,
        public ?int $durationMs = null,
        public ?int $resultLength = null,
        public ?array $errorPayload = null,
        public ?int $toolCallIndex = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromStreamData(array $data): self
    {
        return new self(
            status: (string) ($data['status'] ?? 'success'),
            resultPreview: isset($data['result_preview']) ? (string) $data['result_preview'] : null,
            durationMs: isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            resultLength: isset($data['result_length']) ? (int) $data['result_length'] : null,
            errorPayload: is_array($data['error_payload'] ?? null) ? $data['error_payload'] : null,
            toolCallIndex: isset($data['tool_call_index']) ? (int) $data['tool_call_index'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toEventPayload(string $toolName): array
    {
        return array_filter([
            'tool' => $toolName,
            'status' => $this->status,
            'tool_call_index' => $this->toolCallIndex,
            'result_preview' => $this->resultPreview,
            'duration_ms' => $this->durationMs,
            'result_length' => $this->resultLength,
            'error_payload' => $this->errorPayload,
        ], fn ($value) => $value !== null);
    }
}
