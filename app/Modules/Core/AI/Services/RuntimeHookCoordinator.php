<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Services\Orchestration\RuntimeHookRunner;

class RuntimeHookCoordinator
{
    public function __construct(
        private readonly RuntimeHookRunner $hookRunner,
    ) {}

    public function preContextBuild(string $runId, int $employeeId, ?string $systemPrompt): ?string
    {
        if (! $this->hookRunner->hasHooksFor(HookStage::PreContextBuild)) {
            return $systemPrompt;
        }

        $result = $this->hookRunner->run(
            HookStage::PreContextBuild,
            $this->buildPayload(
                HookStage::PreContextBuild,
                $runId,
                $employeeId,
                ['system_prompt' => $systemPrompt],
            ),
        );

        if ($result->promptSections === []) {
            return $systemPrompt;
        }

        $additions = implode("\n\n", $result->promptSections);

        return $systemPrompt !== null
            ? $systemPrompt."\n\n".$additions
            : $additions;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    public function preToolRegistry(string $runId, int $employeeId, array $tools): array
    {
        if (! $this->hookRunner->hasHooksFor(HookStage::PreToolRegistry)) {
            return $tools;
        }

        $toolNames = array_map(fn (array $tool): string => $tool['function']['name'] ?? '', $tools);
        $result = $this->hookRunner->run(
            HookStage::PreToolRegistry,
            $this->buildPayload(
                HookStage::PreToolRegistry,
                $runId,
                $employeeId,
                ['tool_names' => $toolNames],
            ),
        );

        if ($result->toolsToRemove === []) {
            return $tools;
        }

        $removeSet = array_flip($result->toolsToRemove);

        return array_values(array_filter(
            $tools,
            fn (array $tool): bool => ! isset($removeSet[$tool['function']['name'] ?? '']),
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $hookMetadata
     */
    public function preLlmCall(string $runId, int $employeeId, int $iteration, array &$hookMetadata): void
    {
        if (! $this->hookRunner->hasHooksFor(HookStage::PreLlmCall)) {
            return;
        }

        $result = $this->hookRunner->run(
            HookStage::PreLlmCall,
            $this->buildPayload(
                HookStage::PreLlmCall,
                $runId,
                $employeeId,
                ['iteration' => $iteration],
            ),
        );

        if ($result->hasExecutions()) {
            $hookMetadata['pre_llm_call_'.$iteration] = $result->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $toolAction
     * @param  array<string, array<string, mixed>>  $hookMetadata
     */
    public function postToolResult(string $runId, int $employeeId, array $toolAction, array &$hookMetadata): void
    {
        if (! $this->hookRunner->hasHooksFor(HookStage::PostToolResult)) {
            return;
        }

        $result = $this->hookRunner->run(
            HookStage::PostToolResult,
            $this->buildPayload(
                HookStage::PostToolResult,
                $runId,
                $employeeId,
                ['tool_action' => $toolAction],
            ),
        );

        if ($result->hasExecutions()) {
            $toolName = $toolAction['tool'] ?? 'unknown';
            $hookMetadata['post_tool_'.$toolName] = $result->toArray();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $hookMetadata
     */
    public function postRun(string $runId, int $employeeId, bool $success, array &$hookMetadata): void
    {
        if (! $this->hookRunner->hasHooksFor(HookStage::PostRun)) {
            return;
        }

        $result = $this->hookRunner->run(
            HookStage::PostRun,
            $this->buildPayload(
                HookStage::PostRun,
                $runId,
                $employeeId,
                ['success' => $success],
            ),
        );

        if ($result->hasExecutions()) {
            $hookMetadata['post_run'] = $result->toArray();
        }
    }

    /**
     * Run pre-tool-use hooks. Can deny a tool call before execution.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, array<string, mixed>>  $hookMetadata
     * @return array{denied: bool, reason: string|null}
     */
    public function preToolUse(
        string $runId,
        int $employeeId,
        string $toolName,
        array $arguments,
        array &$hookMetadata,
    ): array {
        if (! $this->hookRunner->hasHooksFor(HookStage::PreToolUse)) {
            return ['denied' => false, 'reason' => null];
        }

        $result = $this->hookRunner->run(
            HookStage::PreToolUse,
            $this->buildPayload(
                HookStage::PreToolUse,
                $runId,
                $employeeId,
                ['tool_name' => $toolName, 'arguments' => $arguments],
            ),
        );

        if ($result->hasExecutions()) {
            $hookMetadata['pre_tool_use_'.$toolName] = $result->toArray();
        }

        $denied = (bool) ($result->augmentations['denied'] ?? false);
        $reason = $denied ? ($result->augmentations['reason'] ?? 'Denied by hook') : null;

        return ['denied' => $denied, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildPayload(HookStage $stage, string $runId, int $employeeId, array $data = []): HookPayload
    {
        return new HookPayload(
            stage: $stage,
            runId: $runId,
            employeeId: $employeeId,
            data: $data,
        );
    }
}
