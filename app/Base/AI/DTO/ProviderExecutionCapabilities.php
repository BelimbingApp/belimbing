<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;

final readonly class ProviderExecutionCapabilities
{
    public function __construct(
        /** @var list<ReasoningVisibility> */
        public array $supportedReasoningVisibility = [],
        /** @var list<ReasoningEffort> */
        public array $supportedReasoningEffort = [],
        public bool $supportsReasoningBudget = false,
        public bool $supportsReasoningContextPreservation = false,
        /** @var list<ToolChoiceMode> */
        public array $supportedToolChoiceModesWhenReasoning = [
            ToolChoiceMode::Auto,
            ToolChoiceMode::None,
            ToolChoiceMode::Required,
        ],
        public bool $supportsNativeReasoningBlocks = false,
        public bool $supportsAdaptiveReasoning = false,
        public ?int $defaultReasoningBudget = null,
        public ?string $interleavedThinkingBetaHeader = null,
        public ?ReasoningVisibility $agenticToolLoopReasoningVisibility = null,
        public bool $preserveReasoningContextInAgenticToolLoops = false,
    ) {}
}
