<?php

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningMode;

final class OpenAiChatCompletionsRequestMapper implements ProviderRequestMapper
{
    use OpenAiRequestMapperHelpers;

    public function __construct(
        private readonly ProviderRequestHeaderResolver $headers,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            'max_tokens' => $request->executionControls->limits->maxOutputTokens,
            'stream' => $stream ? true : null,
            'stream_options' => $stream && $request->providerName === 'openai'
                ? ['include_usage' => true]
                : null,
            'tools' => $this->normalizeTools($request->tools),
            'tool_choice' => $request->executionControls->tools->choice?->value,
        ];

        $this->applyReasoningControls($request, $payload);

        return new ProviderRequestMapping(
            payload: array_filter($payload, fn ($value) => $value !== null),
            headers: $this->headers->headersFor($request),
            controlAdjustments: [],
        );
    }

    /**
     * Map reasoning controls onto the provider-specific wire contract.
     *
     * Kimi K3 takes a top-level `reasoning_effort` and rejects the K2-style
     * `thinking` object; K2.5/K2.6 toggle via `thinking` (K2.6 adds
     * `keep: "all"` for preserved reasoning); always-thinking Kimi models
     * accept no controls. Other Chat Completions providers keep the legacy
     * behavior of announcing disabled reasoning via `thinking`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyReasoningControls(ChatRequest $request, array &$payload): void
    {
        $reasoning = $request->executionControls->reasoning;
        $family = KimiModelFamily::fromModel($request->model);

        if ($family === KimiModelFamily::K3) {
            if ($reasoning->effort === ReasoningEffort::Max) {
                $payload['reasoning_effort'] = $reasoning->effort->value;
            }

            return;
        }

        if ($family === KimiModelFamily::K2AlwaysThinking) {
            return;
        }

        if ($reasoning->mode === ReasoningMode::Disabled) {
            $payload['thinking'] = ['type' => 'disabled'];

            return;
        }

        if ($family === KimiModelFamily::K2ThinkingKeep
            && $request->executionControls->tools->preserveReasoningContext) {
            $payload['thinking'] = ['type' => 'enabled', 'keep' => 'all'];
        }
    }
}
