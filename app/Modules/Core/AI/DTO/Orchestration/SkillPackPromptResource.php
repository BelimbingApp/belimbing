<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

/**
 * A prompt resource bundled within a skill pack.
 */
final readonly class SkillPackPromptResource
{
    /**
     * @param  string  $label  Section label for prompt assembly
     * @param  string  $content  Prompt text content
     * @param  int  $order  Assembly order (lower = earlier)
     */
    public function __construct(
        public string $label,
        public string $content,
        public int $order = 100,
    ) {}

    /**
     * @return array{label: string, content: string, order: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'content' => $this->content,
            'order' => $this->order,
        ];
    }
}
