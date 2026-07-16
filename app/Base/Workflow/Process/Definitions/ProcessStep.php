<?php

namespace App\Base\Workflow\Process\Definitions;

use App\Base\Workflow\Process\Enums\DependencyMode;
use InvalidArgumentException;

final readonly class ProcessStep
{
    /**
     * @param  list<ProcessDependency>  $dependencies
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $dependencies = [],
        public DependencyMode $dependencyMode = DependencyMode::ALL,
        public ?string $requiredSignal = null,
        public int $delaySeconds = 0,
        public int $maxAttempts = 1,
        public array $input = [],
        public ?string $executorKey = null,
        public array $metadata = [],
        public int $priority = 0,
        public ?string $inputRef = null,
        public ?string $resultRef = null,
    ) {
        if ($this->key === '' || $this->label === '') {
            throw new InvalidArgumentException('Process steps need a key and label.');
        }

        if ($this->delaySeconds < 0 || $this->maxAttempts < 1) {
            throw new InvalidArgumentException('Process step delay must be non-negative and max attempts must be positive.');
        }

        foreach ($this->dependencies as $dependency) {
            if (! $dependency instanceof ProcessDependency) {
                throw new InvalidArgumentException('Process step dependencies must be ProcessDependency values.');
            }
        }

        if ($this->executorKey !== null && trim($this->executorKey) === '') {
            throw new InvalidArgumentException('A process step executor key cannot be empty.');
        }
    }

    public function resolvedExecutorKey(): string
    {
        return $this->executorKey ?? $this->key;
    }
}
