<?php
namespace App\Core\AI\DTO;

use App\Core\AI\Enums\ExecutionMode;

final readonly class LaraTaskExecutionProfile
{
    /**
     * @param  list<string>  $allowedToolNames
     */
    public function __construct(
        public string $taskKey,
        public string $label,
        public string $systemPromptPath,
        public array $allowedToolNames,
        public ExecutionMode $executionMode = ExecutionMode::Background,
    ) {}
}
