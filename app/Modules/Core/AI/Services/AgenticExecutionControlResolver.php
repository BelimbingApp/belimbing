<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\AI\Services\ProviderMapping\ProviderCapabilityRegistry;

/**
 * Resolves runtime execution controls for agentic tool-loop requests.
 *
 * Agentic orchestration should not branch on provider quirks inline. This
 * resolver applies tool-loop defaults from Base AI capability metadata and
 * keeps provider/protocol policy out of the runtime call sites.
 */
final class AgenticExecutionControlResolver
{
    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
    ) {}

    public function resolve(
        ExecutionControls $controls,
        ?string $providerName,
        string $model,
        AiApiType $apiType,
        bool $hasTools,
    ): ExecutionControls {
        $resolved = $hasTools
            ? $controls->withToolChoice(ToolChoiceMode::Auto)
            : $controls->withToolChoice(null);

        $capabilities = $this->capabilities->capabilitiesFor($providerName, $model, $apiType);

        if ($capabilities->agenticToolLoopReasoningVisibility !== null) {
            $resolved = $resolved->withReasoningVisibility($capabilities->agenticToolLoopReasoningVisibility);
        }

        if ($capabilities->preserveReasoningContextInAgenticToolLoops) {
            $resolved = $resolved->withReasoningContextPreservation(true);
        }

        return $resolved;
    }
}
