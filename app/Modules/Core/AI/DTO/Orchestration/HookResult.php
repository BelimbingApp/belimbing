<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

/**
 * Explicit augmentation returned by a runtime hook.
 *
 * Hooks return this to declare what they want to add or modify.
 * The runtime merges results under explicit rules — hooks cannot
 * silently suppress core behavior.
 */
final readonly class HookResult
{
    /**
     * @param  bool  $handled  Whether the hook performed its intended work
     * @param  array<string, mixed>  $augmentations  Key-value augmentations to merge into runtime state
     * @param  list<string>  $promptSections  Additional prompt sections to inject (PreContextBuild)
     * @param  list<string>  $toolsToAdd  Tool names to add to the registry (PreToolRegistry)
     * @param  list<string>  $toolsToRemove  Tool names to remove from the registry (PreToolRegistry)
     * @param  array<string, mixed>  $metadata  Diagnostic metadata for run audit
     */
    public function __construct(
        public bool $handled = true,
        public array $augmentations = [],
        public array $promptSections = [],
        public array $toolsToAdd = [],
        public array $toolsToRemove = [],
        public array $metadata = [],
    ) {}

    /**
     * Create a no-op result indicating the hook chose not to act.
     */
    public static function noop(): self
    {
        return new self(handled: false);
    }

    /**
     * Whether the hook produced any effective changes.
     */
    public function hasChanges(): bool
    {
        return $this->augmentations !== []
            || $this->promptSections !== []
            || $this->toolsToAdd !== []
            || $this->toolsToRemove !== [];
    }
}
