<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\LaraTaskDefinition;
use App\Modules\Core\AI\Enums\LaraTaskType;

class LaraTaskProfileSelector
{
    public function __construct(
        private readonly LaraTaskRegistry $taskRegistry,
        private readonly LaraTaskExecutionProfileRegistry $profileRegistry,
    ) {}

    /**
     * @return array{definition: LaraTaskDefinition, reasons: list<string>, confidence: int}|null
     */
    public function select(string $task, ?string $taskType = null): ?array
    {
        $bestDefinition = null;
        $bestReasons = [];
        $bestScore = 0;

        foreach ($this->runtimeReadyAgenticTasks() as $definition) {
            [$score, $reasons] = $this->scoreTask($definition, $task, $taskType);

            if ($score <= $bestScore) {
                continue;
            }

            $bestDefinition = $definition;
            $bestReasons = $reasons;
            $bestScore = $score;
        }

        if ($bestDefinition !== null) {
            return [
                'definition' => $bestDefinition,
                'reasons' => $bestReasons,
                'confidence' => $bestScore,
            ];
        }

        $fallback = $this->taskRegistry->find('coding');

        if ($fallback === null || ! $fallback->runtimeReady || $this->profileRegistry->find('coding') === null) {
            return null;
        }

        return [
            'definition' => $fallback,
            'reasons' => ['No specialized task profile matched clearly; defaulting to Lara Coding.'],
            'confidence' => 0,
        ];
    }

    /**
     * @return list<LaraTaskDefinition>
     */
    private function runtimeReadyAgenticTasks(): array
    {
        return array_values(array_filter(
            $this->taskRegistry->all(),
            fn (LaraTaskDefinition $definition): bool => $definition->type === LaraTaskType::Agentic
                && $definition->runtimeReady
                && $this->profileRegistry->find($definition->key) !== null,
        ));
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function scoreTask(LaraTaskDefinition $definition, string $task, ?string $taskType): array
    {
        $score = 0;
        $reasons = [];
        $normalizedTask = mb_strtolower($task);
        $normalizedTaskType = $taskType !== null ? mb_strtolower(trim($taskType)) : null;

        if ($normalizedTaskType !== null && $normalizedTaskType !== '') {
            if ($normalizedTaskType === mb_strtolower($definition->key)) {
                $score += 120;
                $reasons[] = 'Explicit task type matched '.$definition->label.'.';
            }

            foreach ($definition->routingTaskTypes as $routingTaskType) {
                if ($normalizedTaskType !== mb_strtolower($routingTaskType)) {
                    continue;
                }

                $score += 80;
                $reasons[] = 'Task type matched '.$definition->label.' routing hints.';
                break;
            }
        }

        foreach ($definition->routingKeywords as $keyword) {
            if (! str_contains($normalizedTask, mb_strtolower($keyword))) {
                continue;
            }

            $score += str_contains($keyword, ' ') ? 24 : 14;
            $reasons[] = 'Keyword match: '.$keyword.'.';
        }

        return [$score, $reasons];
    }
}
