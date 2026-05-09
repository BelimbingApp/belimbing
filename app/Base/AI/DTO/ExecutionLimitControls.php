<?php
namespace App\Base\AI\DTO;

final readonly class ExecutionLimitControls
{
    public function __construct(
        public int $maxOutputTokens,
    ) {}

    /**
     * @return array{max_output_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'max_output_tokens' => $this->maxOutputTokens,
        ];
    }
}
