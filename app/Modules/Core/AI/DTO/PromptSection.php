<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\PromptSectionType;

/**
 * A single section within a prompt package.
 *
 * Sections are assembled in order and rendered into the final system prompt.
 * The type classification enables context budgeting and diagnostics.
 */
final readonly class PromptSection
{
    public function __construct(
        public string $label,
        public string $content,
        public PromptSectionType $type,
        public int $order,
        public ?string $source = null,
    ) {}

    /**
     * Content size in bytes (UTF-8).
     */
    public function size(): int
    {
        return strlen($this->content);
    }

    /**
     * Diagnostic array representation (excludes content for safe logging).
     *
     * @return array{label: string, type: string, order: int, source: string|null, size_bytes: int}
     */
    public function describe(): array
    {
        return [
            'label' => $this->label,
            'type' => $this->type->value,
            'order' => $this->order,
            'source' => $this->source,
            'size_bytes' => $this->size(),
        ];
    }
}
