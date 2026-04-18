<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderControlAdjustment;
use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\ProviderControlAdjustmentType;
use App\Base\AI\Enums\ReasoningMode;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\Support\Json as BlbJson;

final class AnthropicMessagesRequestMapper implements ProviderRequestMapper
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        $capabilities = $this->capabilities->capabilitiesFor($request->providerName, $request->model, $request->apiType);
        $adjustments = [];
        [$system, $messages] = $this->convertMessages($request->messages);

        $payload = array_filter([
            'model' => $request->model,
            'system' => $system,
            'messages' => $messages,
            'max_tokens' => $request->executionControls->limits->maxOutputTokens,
            'stream' => $stream,
            'temperature' => $request->executionControls->sampling->temperature,
            'top_p' => $request->executionControls->sampling->topP,
            'tools' => $request->tools !== null ? $this->convertTools($request->tools) : null,
            'tool_choice' => $this->mapToolChoice($request, $adjustments),
            'thinking' => $this->mapThinking($request, $capabilities, $adjustments),
        ], fn (mixed $value): bool => $value !== null);

        $headers = ['anthropic-version' => self::API_VERSION];

        if (
            ($payload['tools'] ?? null) !== null
            && ($payload['thinking'] ?? null) !== null
            && ($payload['thinking']['type'] ?? null) !== 'disabled'
            && $capabilities->interleavedThinkingBetaHeader !== null
        ) {
            $headers['anthropic-beta'] = $capabilities->interleavedThinkingBetaHeader;
        }

        return new ProviderRequestMapping(
            payload: $payload,
            headers: $headers,
            controlAdjustments: $adjustments,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: ?string, 1: list<array<string, mixed>>}
     */
    private function convertMessages(array $messages): array
    {
        $system = [];
        $anthropicMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? null;

            if ($role === 'system') {
                $content = $message['content'] ?? null;
                if (is_string($content) && $content !== '') {
                    $system[] = $content;
                }

                continue;
            }

            $converted = match ($role) {
                'user' => $this->convertUserMessage($message),
                'assistant' => $this->convertAssistantMessage($message),
                'tool' => $this->convertToolMessage($message),
                default => null,
            };

            if ($converted !== null) {
                $anthropicMessages[] = $converted;
            }
        }

        $systemPrompt = implode("\n\n", array_filter($system));

        return [$systemPrompt !== '' ? $systemPrompt : null, $anthropicMessages];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{role: string, content: string|list<array<string, mixed>>}|null
     */
    private function convertUserMessage(array $message): ?array
    {
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return ['role' => 'user', 'content' => $content];
        }

        if (! is_array($content)) {
            return null;
        }

        $blocks = [];

        foreach ($content as $part) {
            if (($part['type'] ?? null) === 'text') {
                $blocks[] = [
                    'type' => 'text',
                    'text' => (string) ($part['text'] ?? ''),
                ];

                continue;
            }

            if (($part['type'] ?? null) !== 'image_url') {
                continue;
            }

            $url = $part['image_url']['url'] ?? null;
            if (! is_string($url) || ! str_starts_with($url, 'data:')) {
                continue;
            }

            $imageSource = $this->convertDataUrlToImageSource($url);

            if ($imageSource !== null) {
                $blocks[] = [
                    'type' => 'image',
                    'source' => $imageSource,
                ];
            }
        }

        return $blocks === [] ? null : ['role' => 'user', 'content' => $blocks];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{role: string, content: list<array<string, mixed>>}|null
     */
    private function convertAssistantMessage(array $message): ?array
    {
        $blocks = [];

        foreach ($message['reasoning_blocks'] ?? [] as $block) {
            if (is_array($block) && isset($block['type'])) {
                $blocks[] = $block;
            }
        }

        $content = $message['content'] ?? null;
        if (is_string($content) && $content !== '') {
            $blocks[] = [
                'type' => 'text',
                'text' => $content,
            ];
        }

        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => (string) ($toolCall['id'] ?? ''),
                'name' => (string) ($toolCall['function']['name'] ?? ''),
                'input' => $this->decodeToolArguments($toolCall['function']['arguments'] ?? '{}'),
            ];
        }

        return $blocks === [] ? null : ['role' => 'assistant', 'content' => $blocks];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{role: string, content: list<array<string, mixed>>}|null
     */
    private function convertToolMessage(array $message): ?array
    {
        $toolCallId = $message['tool_call_id'] ?? null;

        if (! is_string($toolCallId) || $toolCallId === '') {
            return null;
        }

        return [
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => $toolCallId,
                'content' => (string) ($message['content'] ?? ''),
            ]],
        ];
    }

    /**
     * @return array{type: string, media_type: string, data: string}|null
     */
    private function convertDataUrlToImageSource(string $dataUrl): ?array
    {
        if (! preg_match('/^data:(?<mime>[^;]+);base64,(?<data>.+)$/', $dataUrl, $matches)) {
            return null;
        }

        return [
            'type' => 'base64',
            'media_type' => $matches['mime'],
            'data' => $matches['data'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    private function convertTools(array $tools): array
    {
        return array_map(function (array $tool): array {
            $function = $tool['function'] ?? [];

            return array_filter([
                'name' => $function['name'] ?? $tool['name'] ?? '',
                'description' => $function['description'] ?? $tool['description'] ?? null,
                'input_schema' => $function['parameters'] ?? $tool['input_schema'] ?? ['type' => 'object', 'properties' => []],
            ], fn (mixed $value): bool => $value !== null);
        }, $tools);
    }

    /**
     * @param  list<ProviderControlAdjustment>  $adjustments
     * @return array<string, mixed>|null
     */
    private function mapToolChoice(ChatRequest $request, array &$adjustments): ?array
    {
        if ($request->tools === null || $request->tools === []) {
            return null;
        }

        $toolChoice = $request->executionControls->tools->choice;
        $thinkingEnabled = $this->shouldEnableThinking($request);

        if ($thinkingEnabled && $toolChoice === ToolChoiceMode::Required) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Forced,
                'tools.choice',
                ToolChoiceMode::Required->value,
                ToolChoiceMode::Auto->value,
                'Anthropic extended thinking only supports auto or none tool choice.',
            );

            return ['type' => 'auto'];
        }

        return match ($toolChoice) {
            ToolChoiceMode::Auto => ['type' => 'auto'],
            ToolChoiceMode::None => ['type' => 'none'],
            ToolChoiceMode::Required => ['type' => 'any'],
            default => null,
        };
    }

    /**
     * @param  list<ProviderControlAdjustment>  $adjustments
     * @return array<string, mixed>|null
     */
    private function mapThinking(
        ChatRequest $request,
        ProviderExecutionCapabilities $capabilities,
        array &$adjustments,
    ): ?array {
        if ($request->executionControls->reasoning->mode === ReasoningMode::Disabled) {
            return ['type' => 'disabled'];
        }

        if (! $this->shouldEnableThinking($request)) {
            return null;
        }

        $visibility = $request->executionControls->reasoning->visibility;
        $display = match ($visibility) {
            ReasoningVisibility::None => 'omitted',
            ReasoningVisibility::Summary => 'summarized',
            ReasoningVisibility::Full => 'summarized',
        };

        if ($visibility === ReasoningVisibility::Full) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Unsupported,
                'reasoning.visibility',
                ReasoningVisibility::Full->value,
                ReasoningVisibility::Summary->value,
                'Anthropic Messages does not expose full reasoning visibility on current Claude models.',
            );
        }

        if (
            $capabilities->supportsAdaptiveReasoning
            && $request->executionControls->reasoning->budget === null
        ) {
            $thinking = [
                'type' => 'adaptive',
                'display' => $display,
            ];

            if ($request->executionControls->reasoning->effort !== null) {
                $thinking['effort'] = $request->executionControls->reasoning->effort->value;
            }

            return $thinking;
        }

        if ($request->executionControls->reasoning->effort !== null && ! $capabilities->supportsAdaptiveReasoning) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Unsupported,
                'reasoning.effort',
                $request->executionControls->reasoning->effort->value,
                null,
                'Anthropic effort control requires adaptive thinking support on the selected model.',
            );
        }

        $budget = $request->executionControls->reasoning->budget ?? $capabilities->defaultReasoningBudget ?? 2048;
        $maxTokens = $request->executionControls->limits->maxOutputTokens;
        $budget = min($budget, max($maxTokens - 1, 1024));

        if ($request->executionControls->reasoning->budget === null) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Forced,
                'reasoning.budget',
                null,
                $budget,
                'Anthropic enabled thinking requires an explicit budget, so BLB supplied the provider default.',
            );
        }

        return [
            'type' => 'enabled',
            'budget_tokens' => $budget,
            'display' => $display,
        ];
    }

    private function shouldEnableThinking(ChatRequest $request): bool
    {
        return $request->executionControls->reasoning->mode === ReasoningMode::Enabled
            || $request->executionControls->reasoning->visibility !== ReasoningVisibility::None
            || $request->executionControls->reasoning->effort !== null
            || $request->executionControls->reasoning->budget !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeToolArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        return BlbJson::decodeArray($arguments) ?? [];
    }
}
