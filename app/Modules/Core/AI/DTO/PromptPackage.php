<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Assembled prompt package ready for rendering.
 *
 * Contains ordered prompt sections plus workspace and validation metadata.
 * Callers use this to render the final system prompt or inspect what shaped it.
 */
final readonly class PromptPackage
{
    /**
     * @param  list<PromptSection>  $sections  Ordered prompt sections
     * @param  WorkspaceManifest  $manifest  Resolved workspace state
     * @param  WorkspaceValidationResult  $validation  Validation outcome
     */
    public function __construct(
        public array $sections,
        public WorkspaceManifest $manifest,
        public WorkspaceValidationResult $validation,
    ) {}

    /**
     * Total rendered size in bytes across all sections.
     */
    public function totalSize(): int
    {
        $total = 0;

        foreach ($this->sections as $section) {
            $total += $section->size();
        }

        return $total;
    }

    /**
     * Operator-facing metadata describing the effective prompt package.
     *
     * Safe for logging and run metadata — contains no prompt content.
     *
     * @return array{section_count: int, total_size_bytes: int, sections: list<array<string, mixed>>, workspace: array<string, mixed>, validation: array<string, mixed>}
     */
    public function describe(): array
    {
        return [
            'section_count' => count($this->sections),
            'total_size_bytes' => $this->totalSize(),
            'sections' => array_map(
                fn (PromptSection $section): array => $section->describe(),
                $this->sections,
            ),
            'workspace' => $this->manifest->toArray(),
            'validation' => $this->validation->toArray(),
        ];
    }
}
