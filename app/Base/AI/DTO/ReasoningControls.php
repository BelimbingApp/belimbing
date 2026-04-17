<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningMode;
use App\Base\AI\Enums\ReasoningVisibility;

final readonly class ReasoningControls
{
    public function __construct(
        public ReasoningMode $mode = ReasoningMode::Auto,
        public ReasoningVisibility $visibility = ReasoningVisibility::None,
        public ?ReasoningEffort $effort = null,
        public ?int $budget = null,
    ) {}

    /**
     * @return array{mode: string, visibility: string, effort: ?string, budget: ?int}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'visibility' => $this->visibility->value,
            'effort' => $this->effort?->value,
            'budget' => $this->budget,
        ];
    }
}
