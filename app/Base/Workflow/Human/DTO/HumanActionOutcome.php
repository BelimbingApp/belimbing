<?php

namespace App\Base\Workflow\Human\DTO;

final readonly class HumanActionOutcome
{
    /** @param array<string, mixed> $output */
    public function __construct(
        public array $output = [],
        public string $outcome = 'completed',
        public ?string $resultRef = null,
    ) {}
}
