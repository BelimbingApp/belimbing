<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderControlAdjustment;
use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ProviderControlAdjustmentType;
use App\Base\AI\Enums\ReasoningVisibility;

final class OpenAiResponsesRequestMapper implements ProviderRequestMapper
{
    use OpenAiRequestMapperHelpers;

    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
        private readonly ProviderRequestHeaderResolver $headers,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        [$instructions, $input] = $this->convertToResponsesInputWithInstructions($request->messages);
        $capabilities = $this->capabilities->capabilitiesFor($request->providerName, $request->model, $request->apiType);
        $adjustments = [];
        $payload = $this->basePayload(
            $request,
            $stream,
            $this->defaultInstructions($request, $instructions),
            $input,
        );

        $this->applyReasoningVisibility($request, $capabilities, $payload, $adjustments);
        $this->applyReasoningEffort($request, $capabilities, $payload, $adjustments);
        $this->applyReasoningBudget($request, $capabilities, $payload, $adjustments);

        return new ProviderRequestMapping(
            payload: $payload,
            headers: $this->headers->headersFor($request),
            controlAdjustments: $adjustments,
        );
    }

    private function defaultInstructions(ChatRequest $request, ?string $instructions): ?string
    {
        if ($request->apiType !== AiApiType::OpenAiCodexResponses) {
            return $instructions;
        }

        if (is_string($instructions) && trim($instructions) !== '') {
            return $instructions;
        }

        return 'You are Codex, a coding assistant. Follow the user request exactly and reply concisely.';
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return array<string, mixed>
     */
    private function basePayload(ChatRequest $request, bool $stream, ?string $instructions, array $input): array
    {
        $payload = array_filter([
            'model' => $request->model,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => $request->executionControls->limits->maxOutputTokens,
            'stream' => $stream,
            'store' => false,
            'tools' => $request->tools !== null ? $this->convertToResponsesTools($request->tools) : null,
            'tool_choice' => $request->executionControls->tools->choice?->value,
        ], fn ($value) => $value !== null);

        if ($request->apiType !== AiApiType::OpenAiCodexResponses) {
            return $payload;
        }

        unset($payload['max_output_tokens']);

        $payload['text'] = ['verbosity' => 'medium'];
        $payload['include'] = ['reasoning.encrypted_content'];
        $payload['parallel_tool_calls'] = true;
        $payload['tool_choice'] ??= 'auto';

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ProviderControlAdjustment>  $adjustments
     */
    private function applyReasoningVisibility(
        ChatRequest $request,
        ProviderExecutionCapabilities $capabilities,
        array &$payload,
        array &$adjustments,
    ): void {
        if (
            in_array($request->executionControls->reasoning->visibility, $capabilities->supportedReasoningVisibility, true)
            && $request->executionControls->reasoning->visibility === ReasoningVisibility::Summary
        ) {
            $payload['reasoning'] = ['summary' => 'auto'];

            return;
        }

        if ($request->executionControls->reasoning->visibility === ReasoningVisibility::Full) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Unsupported,
                'reasoning.visibility',
                $request->executionControls->reasoning->visibility->value,
                null,
                'OpenAI Responses supports summary visibility, not full reasoning visibility.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ProviderControlAdjustment>  $adjustments
     */
    private function applyReasoningEffort(
        ChatRequest $request,
        ProviderExecutionCapabilities $capabilities,
        array &$payload,
        array &$adjustments,
    ): void {
        if ($request->executionControls->reasoning->effort === null) {
            return;
        }

        if (in_array($request->executionControls->reasoning->effort, $capabilities->supportedReasoningEffort, true)) {
            $payload['reasoning']['effort'] = $request->executionControls->reasoning->effort->value;

            return;
        }

        $adjustments[] = new ProviderControlAdjustment(
            ProviderControlAdjustmentType::Unsupported,
            'reasoning.effort',
            $request->executionControls->reasoning->effort->value,
            null,
            'OpenAI Responses does not support the requested reasoning effort for this model.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ProviderControlAdjustment>  $adjustments
     */
    private function applyReasoningBudget(
        ChatRequest $request,
        ProviderExecutionCapabilities $capabilities,
        array &$payload,
        array &$adjustments,
    ): void {
        if ($request->executionControls->reasoning->budget === null) {
            return;
        }

        if ($capabilities->supportsReasoningBudget) {
            $payload['reasoning']['budget_tokens'] = $request->executionControls->reasoning->budget;

            return;
        }

        $adjustments[] = new ProviderControlAdjustment(
            ProviderControlAdjustmentType::Unsupported,
            'reasoning.budget',
            $request->executionControls->reasoning->budget,
            null,
            'OpenAI Responses does not support reasoning budgets for this model.',
        );
    }
}
