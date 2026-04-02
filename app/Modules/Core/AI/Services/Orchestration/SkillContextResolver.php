<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SkillPackHookBinding;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;

/**
 * Resolves which skill packs apply for an agent and task, then
 * merges their prompt resources, tool bindings, references, and
 * hook registrations into a unified resolution result.
 *
 * Resolution is the bridge between the registry (what exists) and
 * the runtime (what gets used). It enforces policy applicability
 * and availability, producing an auditable record of which packs
 * contributed to a given run.
 *
 * The resolver does NOT execute hooks or inject tools — it produces
 * a resolution DTO that the runtime consumes at specific hook stages.
 */
class SkillContextResolver
{
    public function __construct(
        private readonly SkillPackRegistry $registry,
        private readonly OrchestrationPolicyService $policy,
    ) {}

    /**
     * Resolve all applicable skill packs for an agent.
     *
     * Filters registered packs by policy applicability and availability,
     * then merges their resources into a unified resolution result.
     *
     * @return SkillResolution Merged resources from all applicable packs
     */
    public function resolve(int $employeeId): SkillResolution
    {
        $applicablePacks = $this->resolveApplicablePacks($employeeId);

        if ($applicablePacks === []) {
            return SkillResolution::empty();
        }

        return $this->mergePackResources($applicablePacks);
    }

    /**
     * Resolve with additional task context for pack selection.
     *
     * Currently task context does not further filter packs (packs
     * declare agent-level applicability). This method exists as the
     * extension point for future task-scoped pack resolution.
     *
     * @param  int  $employeeId  Agent executing the task
     * @param  string  $task  Task description (reserved for future filtering)
     * @param  string|null  $taskType  Task type (reserved for future filtering)
     */
    public function resolveForTask(int $employeeId, string $task, ?string $taskType = null): SkillResolution
    {
        if ($task === '' && $taskType === null) {
            return $this->resolve($employeeId);
        }

        $applicablePacks = $this->resolveApplicablePacks($employeeId);

        if ($applicablePacks === []) {
            return SkillResolution::empty();
        }

        return $this->mergePackResources($applicablePacks);
    }

    /**
     * List pack IDs that would apply for an agent (without full merge).
     *
     * Useful for diagnostics and metadata without the cost of merging.
     *
     * @return list<string>
     */
    public function applicablePackIds(int $employeeId): array
    {
        return array_map(
            fn (SkillPackManifest $m): string => $m->id,
            $this->resolveApplicablePacks($employeeId),
        );
    }

    /**
     * Filter registered packs by policy and availability.
     *
     * @return list<SkillPackManifest>
     */
    private function resolveApplicablePacks(int $employeeId): array
    {
        $candidates = $this->registry->all();
        $applicable = [];

        foreach ($candidates as $manifest) {
            if ($this->policy->isSkillPackApplicable($manifest, $employeeId)) {
                $applicable[] = $manifest;
            }
        }

        return $applicable;
    }

    /**
     * Merge resources from multiple pack manifests into a single resolution.
     *
     * Prompt resources are merged and sorted by order. Tool bindings and
     * references are deduplicated. Hook bindings are collected for the
     * runtime hook runner to consume.
     *
     * @param  list<SkillPackManifest>  $packs
     */
    private function mergePackResources(array $packs): SkillResolution
    {
        $promptResources = [];
        $toolBindings = [];
        $references = [];
        $hookBindings = [];
        $resolvedPackIds = [];

        foreach ($packs as $manifest) {
            $resolvedPackIds[] = $manifest->id;

            foreach ($manifest->promptResources as $resource) {
                $promptResources[] = $resource;
            }

            foreach ($manifest->toolBindings as $toolName) {
                $toolBindings[$toolName] = true;
            }

            foreach ($manifest->references as $reference) {
                $references[] = $reference;
            }

            foreach ($manifest->hookBindings as $hookBinding) {
                $hookBindings[] = $hookBinding;
            }
        }

        // Sort prompt resources by order (stable sort preserves registration order for ties)
        usort($promptResources, fn (SkillPackPromptResource $a, SkillPackPromptResource $b): int => $a->order <=> $b->order);

        // Sort hook bindings by priority
        usort($hookBindings, fn (SkillPackHookBinding $a, SkillPackHookBinding $b): int => $a->priority <=> $b->priority);

        return new SkillResolution(
            resolvedPackIds: $resolvedPackIds,
            promptResources: $promptResources,
            toolBindings: array_keys($toolBindings),
            references: $references,
            hookBindings: $hookBindings,
        );
    }
}
