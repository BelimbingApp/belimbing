<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

trait OpenAiRequestMapperHelpers
{
    /**
     * @param  list<array<string, mixed>>|null  $tools
     * @return list<array<string, mixed>>|null
     */
    private function normalizeTools(?array $tools): ?array
    {
        return $tools;
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
