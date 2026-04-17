<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningVisibility;

final readonly class ProviderExecutionCapabilities
{
    public function __construct(
        public bool $requiresAnyOfToolSchemas = false,
        public ?SamplingControls $fixedSamplingWhenReasoningEnabled = null,
        public ?SamplingControls $fixedSamplingWhenReasoningDisabled = null,
        /** @var list<ReasoningVisibility> */
        public array $supportedReasoningVisibility = [],
        /** @var list<ReasoningEffort> */
        public array $supportedReasoningEffort = [],
        public bool $supportsReasoningBudget = false,
        public bool $supportsReasoningContextPreservation = false,
    ) {}
}
