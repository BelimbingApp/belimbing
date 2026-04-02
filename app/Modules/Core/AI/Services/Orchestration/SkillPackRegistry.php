<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\Enums\SkillPackStatus;

/**
 * Central registry for skill pack manifests.
 *
 * Manages the lifecycle of skill packs: registration, lookup, readiness
 * verification, and listing. Packs are registered by ID and validated
 * for uniqueness. The registry is the single source of truth for which
 * packs exist — resolution of which packs apply to a given agent/task
 * is handled by SkillContextResolver.
 *
 * Packs are code-registered (not stored in DB) during initialization
 * phase. Persistence may be added later when BLB supports dynamic
 * pack management.
 */
class SkillPackRegistry
{
    /** @var array<string, SkillPackManifest> Registered packs keyed by pack ID. */
    private array $packs = [];

    /**
     * Register a skill pack manifest.
     *
     * Rejects duplicate pack IDs to enforce registry uniqueness.
     *
     * @throws \InvalidArgumentException When a pack with the same ID is already registered
     */
    public function register(SkillPackManifest $manifest): void
    {
        if (isset($this->packs[$manifest->id])) {
            throw new \InvalidArgumentException(
                'Skill pack "'.$manifest->id.'" is already registered.',
            );
        }

        $this->packs[$manifest->id] = $manifest;
    }

    /**
     * Unregister a skill pack by ID.
     *
     * Returns true if the pack was found and removed, false if not found.
     */
    public function unregister(string $packId): bool
    {
        if (! isset($this->packs[$packId])) {
            return false;
        }

        unset($this->packs[$packId]);

        return true;
    }

    /**
     * Look up a registered pack by ID.
     */
    public function find(string $packId): ?SkillPackManifest
    {
        return $this->packs[$packId] ?? null;
    }

    /**
     * Whether a pack is registered.
     */
    public function has(string $packId): bool
    {
        return isset($this->packs[$packId]);
    }

    /**
     * Get all registered pack manifests.
     *
     * @return list<SkillPackManifest>
     */
    public function all(): array
    {
        return array_values($this->packs);
    }

    /**
     * Get all packs applicable to a specific agent.
     *
     * Filters by agent applicability from the manifest. Does NOT check
     * readiness/availability — use SkillContextResolver for that.
     *
     * @return list<SkillPackManifest>
     */
    public function forAgent(int $employeeId): array
    {
        return array_values(
            array_filter(
                $this->packs,
                fn (SkillPackManifest $manifest): bool => $manifest->appliesTo($employeeId),
            ),
        );
    }

    /**
     * Get all available (ready) packs applicable to a specific agent.
     *
     * Combines agent applicability and availability checks.
     *
     * @return list<SkillPackManifest>
     */
    public function availableForAgent(int $employeeId): array
    {
        return array_values(
            array_filter(
                $this->packs,
                fn (SkillPackManifest $manifest): bool => $manifest->appliesTo($employeeId)
                    && $manifest->isAvailable(),
            ),
        );
    }

    /**
     * Verify readiness of a registered pack.
     *
     * Returns a diagnostic array of check results. Each entry maps a
     * readiness check description to its pass/fail status and any
     * failure reason. The pack's status is updated to Degraded if
     * any check fails, or Ready if all pass.
     *
     * Currently readiness checks are descriptive strings. As BLB
     * matures, these may become callable validators.
     *
     * @return array{status: SkillPackStatus, checks: list<array{check: string, passed: bool, reason: string|null}>}
     */
    public function verify(string $packId): array
    {
        $manifest = $this->packs[$packId] ?? null;

        if ($manifest === null) {
            return [
                'status' => SkillPackStatus::Disabled,
                'checks' => [['check' => 'pack_exists', 'passed' => false, 'reason' => 'Pack not found in registry.']],
            ];
        }

        $checks = [];
        $allPassed = true;

        // Structural readiness: must have at least one resource type
        $hasResources = $manifest->promptResources !== []
            || $manifest->toolBindings !== []
            || $manifest->references !== [];

        $checks[] = [
            'check' => 'has_resources',
            'passed' => $hasResources,
            'reason' => $hasResources ? null : 'Pack has no prompt resources, tool bindings, or references.',
        ];

        if (! $hasResources) {
            $allPassed = false;
        }

        // Manifest-declared readiness checks (descriptive, logged as passed for now)
        foreach ($manifest->readinessChecks as $checkDescription) {
            $checks[] = [
                'check' => $checkDescription,
                'passed' => true,
                'reason' => null,
            ];
        }

        // Hook class existence checks
        foreach ($manifest->hookBindings as $hookBinding) {
            $exists = class_exists($hookBinding->hookClass);
            $checks[] = [
                'check' => 'hook_class_exists:'.$hookBinding->hookClass,
                'passed' => $exists,
                'reason' => $exists ? null : 'Hook class "'.$hookBinding->hookClass.'" does not exist.',
            ];

            if (! $exists) {
                $allPassed = false;
            }
        }

        $resultStatus = $allPassed ? SkillPackStatus::Ready : SkillPackStatus::Degraded;

        // If the pack's current status is Disabled, preserve it
        if ($manifest->status === SkillPackStatus::Disabled) {
            $resultStatus = SkillPackStatus::Disabled;
        }

        return [
            'status' => $resultStatus,
            'checks' => $checks,
        ];
    }

    /**
     * Number of registered packs.
     */
    public function count(): int
    {
        return count($this->packs);
    }
}
