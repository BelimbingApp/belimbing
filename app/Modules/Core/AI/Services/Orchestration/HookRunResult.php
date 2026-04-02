<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

/**
 * Merged result from executing all hooks at a given stage.
 *
 * Contains the combined augmentations from all hooks plus execution
 * metadata for diagnostics. The runtime consumes this to apply
 * prompt sections, tool changes, and augmentations.
 */
final readonly class HookRunResult
{
    /**
     * @param  list<string>  $promptSections  Merged prompt sections from all hooks
     * @param  list<string>  $toolsToAdd  Deduplicated tool names to add
     * @param  list<string>  $toolsToRemove  Deduplicated tool names to remove
     * @param  array<string, mixed>  $augmentations  Merged key-value augmentations
     * @param  array<string, array<string, mixed>>  $hookMetadata  Per-hook execution metadata
     * @param  int  $executedCount  Number of hooks that executed successfully
     * @param  int  $failedCount  Number of hooks that failed
     */
    public function __construct(
        public array $promptSections = [],
        public array $toolsToAdd = [],
        public array $toolsToRemove = [],
        public array $augmentations = [],
        public array $hookMetadata = [],
        public int $executedCount = 0,
        public int $failedCount = 0,
    ) {}

    /**
     * Create an empty result (no hooks ran).
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Whether any hooks produced changes.
     */
    public function hasChanges(): bool
    {
        return $this->promptSections !== []
            || $this->toolsToAdd !== []
            || $this->toolsToRemove !== []
            || $this->augmentations !== [];
    }

    /**
     * Whether any hooks executed (regardless of whether they produced changes).
     */
    public function hasExecutions(): bool
    {
        return $this->executedCount > 0;
    }

    /**
     * Serialize for run metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prompt_section_count' => count($this->promptSections),
            'tools_to_add' => $this->toolsToAdd,
            'tools_to_remove' => $this->toolsToRemove,
            'augmentation_count' => count($this->augmentations),
            'executed_count' => $this->executedCount,
            'failed_count' => $this->failedCount,
            'hooks' => $this->hookMetadata,
        ];
    }
}
