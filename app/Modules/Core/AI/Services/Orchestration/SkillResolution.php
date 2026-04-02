<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SkillPackHookBinding;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackReference;

/**
 * Merged resolution result from applicable skill packs.
 *
 * Contains the combined prompt resources, tool bindings, references,
 * and hook bindings from all packs that apply to a given agent/task.
 * This is consumed by the runtime at specific hook stages.
 *
 * The resolution also records which pack IDs contributed, so run
 * metadata can audit exactly which packs were active.
 */
final readonly class SkillResolution
{
    /**
     * @param  list<string>  $resolvedPackIds  IDs of packs that contributed to this resolution
     * @param  list<SkillPackPromptResource>  $promptResources  Merged prompt sections (sorted by order)
     * @param  list<string>  $toolBindings  Deduplicated tool names from all packs
     * @param  list<SkillPackReference>  $references  Merged reference documents
     * @param  list<SkillPackHookBinding>  $hookBindings  Merged hook registrations (sorted by priority)
     */
    public function __construct(
        public array $resolvedPackIds = [],
        public array $promptResources = [],
        public array $toolBindings = [],
        public array $references = [],
        public array $hookBindings = [],
    ) {}

    /**
     * Create an empty resolution (no packs applied).
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Whether any packs contributed to this resolution.
     */
    public function hasContent(): bool
    {
        return $this->resolvedPackIds !== [];
    }

    /**
     * Number of packs that contributed.
     */
    public function packCount(): int
    {
        return count($this->resolvedPackIds);
    }

    /**
     * Assemble prompt text from all prompt resources.
     *
     * Concatenates prompt sections in their resolved order with
     * double-newline separators. Returns empty string if no
     * prompt resources exist.
     */
    public function assembledPrompt(): string
    {
        if ($this->promptResources === []) {
            return '';
        }

        return implode(
            "\n\n",
            array_map(
                fn (SkillPackPromptResource $r): string => $r->content,
                $this->promptResources,
            ),
        );
    }

    /**
     * Serialize for run metadata and diagnostics.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resolved_pack_ids' => $this->resolvedPackIds,
            'prompt_resource_count' => count($this->promptResources),
            'tool_bindings' => $this->toolBindings,
            'reference_count' => count($this->references),
            'hook_binding_count' => count($this->hookBindings),
        ];
    }
}
