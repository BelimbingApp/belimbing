<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderControlAdjustment;
use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\Enums\ProviderControlAdjustmentType;
use App\Base\AI\Enums\ReasoningMode;

trait OpenAiRequestMapperHelpers
{
    /**
     * @param  list<array<string, mixed>>|null  $tools
     * @return list<array<string, mixed>>|null
     */
    private function normalizeTools(?array $tools, ProviderExecutionCapabilities $capabilities): ?array
    {
        if ($tools === null) {
            return null;
        }

        if (! $capabilities->requiresAnyOfToolSchemas) {
            return $tools;
        }

        return array_map(function (array $tool): array {
            if (! isset($tool['function']['parameters'])) {
                return $tool;
            }

            $tool['function']['parameters'] = $this->convertOneOfToAnyOf($tool['function']['parameters']);

            return $tool;
        }, $tools);
    }

    private function convertOneOfToAnyOf(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        if (isset($schema['oneOf'])) {
            $schema['anyOf'] = $schema['oneOf'];
            unset($schema['oneOf']);
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->convertOneOfToAnyOf($value);
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ProviderControlAdjustment>  $adjustments
     * @return array<string, mixed>
     */
    private function applyFixedSampling(
        array $payload,
        ChatRequest $request,
        ProviderExecutionCapabilities $capabilities,
        array &$adjustments,
    ): array {
        $fixedSampling = $request->executionControls->reasoning->mode === ReasoningMode::Disabled
            ? $capabilities->fixedSamplingWhenReasoningDisabled
            : $capabilities->fixedSamplingWhenReasoningEnabled;

        if ($fixedSampling === null) {
            return $payload;
        }

        $payload = $this->applyFixedSamplingValue(
            $payload,
            'temperature',
            'sampling.temperature',
            $fixedSampling->temperature,
            'Provider enforces a fixed temperature for this reasoning mode.',
            $adjustments,
        );
        $payload = $this->applyFixedSamplingValue(
            $payload,
            'top_p',
            'sampling.top_p',
            $fixedSampling->topP,
            'Provider enforces a fixed top-p value for this reasoning mode.',
            $adjustments,
        );
        $payload = $this->applyFixedSamplingValue(
            $payload,
            'n',
            'sampling.candidate_count',
            $fixedSampling->candidateCount,
            'Provider enforces a single candidate for this reasoning mode.',
            $adjustments,
        );
        $payload = $this->applyFixedSamplingValue(
            $payload,
            'presence_penalty',
            'sampling.presence_penalty',
            $fixedSampling->presencePenalty,
            'Provider enforces a fixed presence penalty for this reasoning mode.',
            $adjustments,
        );
        $payload = $this->applyFixedSamplingValue(
            $payload,
            'frequency_penalty',
            'sampling.frequency_penalty',
            $fixedSampling->frequencyPenalty,
            'Provider enforces a fixed frequency penalty for this reasoning mode.',
            $adjustments,
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<ProviderControlAdjustment>  $adjustments
     * @return array<string, mixed>
     */
    private function applyFixedSamplingValue(
        array $payload,
        string $payloadKey,
        string $controlPath,
        mixed $appliedValue,
        string $message,
        array &$adjustments,
    ): array {
        if (! array_key_exists($payloadKey, $payload) || $payload[$payloadKey] === null) {
            return $payload;
        }

        if ($payload[$payloadKey] !== $appliedValue) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Forced,
                $controlPath,
                $payload[$payloadKey],
                $appliedValue,
                $message,
            );
        }

        $payload[$payloadKey] = $appliedValue;

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: ?string, 1: list<array<string, mixed>>}
     */
    private function convertToResponsesInputWithInstructions(array $messages): array
    {
        $instructions = [];
        $input = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'system') {
                $instructions[] = $msg['content'] ?? '';

                continue;
            }

            switch ($role) {
                case 'user':
                    $content = $msg['content'] ?? '';
                    $input[] = [
                        'role' => 'user',
                        'content' => is_string($content)
                            ? [['type' => 'input_text', 'text' => $content]]
                            : $this->convertContentPartsToResponses($content),
                    ];
                    break;

                case 'assistant':
                    $this->appendAssistantMessageToResponsesInput($msg, $input);
                    break;

                case 'tool':
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => $msg['tool_call_id'] ?? '',
                        'output' => $msg['content'] ?? '',
                    ];
                    break;

                default:
                    break;
            }
        }

        $instructionText = implode("\n\n", array_filter($instructions));

        return [$instructionText !== '' ? $instructionText : null, $input];
    }

    /**
     * @param  array<string, mixed>  $msg
     * @param  list<array<string, mixed>>  $input
     */
    private function appendAssistantMessageToResponsesInput(array $msg, array &$input): void
    {
        $content = $msg['content'] ?? '';
        if ($content !== '' && $content !== null) {
            $assistantItem = [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $content]],
                'status' => 'completed',
            ];

            if (isset($msg['phase'])) {
                $assistantItem['phase'] = $msg['phase'];
            }

            $input[] = $assistantItem;
        }

        foreach ($msg['tool_calls'] ?? [] as $toolCall) {
            $function = $toolCall['function'] ?? [];
            $input[] = [
                'type' => 'function_call',
                'call_id' => $toolCall['id'] ?? '',
                'name' => $function['name'] ?? '',
                'arguments' => $function['arguments'] ?? '{}',
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return list<array<string, mixed>>
     */
    private function convertContentPartsToResponses(array $content): array
    {
        $parts = [];

        foreach ($content as $part) {
            $type = $part['type'] ?? '';

            if ($type === 'text') {
                $parts[] = ['type' => 'input_text', 'text' => $part['text'] ?? ''];

                continue;
            }

            if ($type === 'image_url') {
                $url = $part['image_url']['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $parts[] = ['type' => 'input_image', 'image_url' => $url];
                }
            }
        }

        return $parts;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    private function convertToResponsesTools(array $tools): array
    {
        return array_map(function (array $tool): array {
            $fn = $tool['function'] ?? [];

            return array_filter([
                'type' => 'function',
                'name' => $fn['name'] ?? $tool['name'] ?? '',
                'description' => $fn['description'] ?? $tool['description'] ?? null,
                'parameters' => $fn['parameters'] ?? $tool['parameters'] ?? null,
            ], fn ($value) => $value !== null);
        }, $tools);
    }
}
