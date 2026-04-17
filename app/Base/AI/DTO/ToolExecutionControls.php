<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\ToolChoiceMode;

final readonly class ToolExecutionControls
{
    public function __construct(
        public ?ToolChoiceMode $choice = null,
        public bool $preserveReasoningContext = false,
    ) {}

    /**
     * @return array{choice: ?string, preserve_reasoning_context: bool}
     */
    public function toArray(): array
    {
        return [
            'choice' => $this->choice?->value,
            'preserve_reasoning_context' => $this->preserveReasoningContext,
        ];
    }
}
