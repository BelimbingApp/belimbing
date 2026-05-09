<?php
namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\ExecutionMode;

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
