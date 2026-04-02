<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\Contracts\Orchestration\RuntimeHook;
use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\DTO\Orchestration\HookResult;
use App\Modules\Core\AI\Enums\HookStage;
use Psr\Log\LoggerInterface;

/**
 * Executes runtime hooks at declared lifecycle stages.
 *
 * The runner loads hooks from the registry for a given stage,
 * executes them in priority order, and merges their results
 * under explicit rules. Hooks receive an immutable payload and
 * return augmentations — they never mutate runtime globals.
 *
 * Merge semantics:
 * - promptSections: concatenated across all hooks (order = priority)
 * - toolsToAdd: unioned across all hooks (deduplicated)
 * - toolsToRemove: unioned across all hooks (deduplicated)
 * - augmentations: merged (later hooks overwrite earlier keys)
 * - metadata: merged per-hook under the hook's identifier key
 *
 * Hook failures are caught and logged. A failing hook does not
 * abort the runtime — it is recorded as a degraded participant.
 */
class RuntimeHookRunner
{
    public function __construct(
        private readonly RuntimeHookRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run all hooks for a given stage and return the merged result.
     *
     * If no hooks are registered for the stage, returns a no-op result
     * immediately to avoid unnecessary allocations.
     */
    public function run(HookStage $stage, HookPayload $payload): HookRunResult
    {
        $hooks = $this->registry->forStage($stage);

        if ($hooks === []) {
            return HookRunResult::empty();
        }

        return $this->executeAndMerge($hooks, $payload);
    }

    /**
     * Whether any hooks are registered for a stage.
     *
     * Useful for callers that want to skip payload construction
     * when no hooks will run.
     */
    public function hasHooksFor(HookStage $stage): bool
    {
        return $this->registry->hasHooksFor($stage);
    }

    /**
     * Execute hooks in order and merge their results.
     *
     * @param  list<RuntimeHook>  $hooks
     */
    private function executeAndMerge(array $hooks, HookPayload $payload): HookRunResult
    {
        $promptSections = [];
        $toolsToAdd = [];
        $toolsToRemove = [];
        $augmentations = [];
        $hookMetadata = [];
        $executedCount = 0;
        $failedCount = 0;

        foreach ($hooks as $hook) {
            $result = $this->executeSafely($hook, $payload);

            if ($result === null) {
                $failedCount++;
                $hookMetadata[$hook->identifier()] = ['status' => 'failed'];

                continue;
            }

            $executedCount++;

            if (! $result->handled) {
                $hookMetadata[$hook->identifier()] = ['status' => 'skipped'];

                continue;
            }

            // Merge prompt sections
            array_push($promptSections, ...$result->promptSections);

            // Union tool additions
            foreach ($result->toolsToAdd as $tool) {
                $toolsToAdd[$tool] = true;
            }

            // Union tool removals
            foreach ($result->toolsToRemove as $tool) {
                $toolsToRemove[$tool] = true;
            }

            // Merge augmentations (later hooks overwrite)
            $augmentations = array_merge($augmentations, $result->augmentations);

            // Record per-hook metadata
            $hookMetadata[$hook->identifier()] = [
                'status' => 'executed',
                'has_changes' => $result->hasChanges(),
                ...$result->metadata,
            ];
        }

        return new HookRunResult(
            promptSections: $promptSections,
            toolsToAdd: array_keys($toolsToAdd),
            toolsToRemove: array_keys($toolsToRemove),
            augmentations: $augmentations,
            hookMetadata: $hookMetadata,
            executedCount: $executedCount,
            failedCount: $failedCount,
        );
    }

    /**
     * Execute a single hook with error isolation.
     *
     * Returns null on failure. The caller records the failure
     * in metadata without aborting the run.
     */
    private function executeSafely(RuntimeHook $hook, HookPayload $payload): ?HookResult
    {
        try {
            return $hook->execute($payload);
        } catch (\Throwable $e) {
            $this->logger->warning('RuntimeHookRunner: hook "'.$hook->identifier().'" failed: '.$e->getMessage(), [
                'hook' => $hook->identifier(),
                'stage' => $payload->stage->value,
            ]);

            return null;
        }
    }
}
