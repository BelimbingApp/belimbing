<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Structured capability catalog for agents.
 *
 * Builds AgentCapabilityDescriptor instances from Employee model data.
 * Agents with structured capability metadata in their `metadata` JSON
 * column get rich descriptors. Agents without structured data fall back
 * to the legacy capability summary from LaraCapabilityMatcher.
 *
 * This catalog is the single source of truth for agent capabilities
 * that feeds routing, UI discovery, and supervision policy. It does
 * NOT own the Employee data — it reads and structures it.
 */
class AgentCapabilityCatalog
{
    private const CAPABILITY_META_KEY = 'ai_capabilities';

    public function __construct(
        private readonly LaraCapabilityMatcher $legacyMatcher,
    ) {}

    /**
     * Build a capability descriptor for a specific agent.
     *
     * Returns null if the employee is not an active agent.
     */
    public function descriptorFor(int $employeeId): ?AgentCapabilityDescriptor
    {
        $employee = Employee::query()
            ->agent()
            ->active()
            ->find($employeeId);

        if (! $employee instanceof Employee) {
            return null;
        }

        return $this->buildDescriptor($employee);
    }

    /**
     * Build capability descriptors for all active agents.
     *
     * @return list<AgentCapabilityDescriptor>
     */
    public function allDescriptors(): array
    {
        return Employee::query()
            ->agent()
            ->active()
            ->get()
            ->map(fn (Employee $employee): AgentCapabilityDescriptor => $this->buildDescriptor($employee))
            ->values()
            ->all();
    }

    /**
     * Build descriptors for agents delegable by the current user.
     *
     * Delegates the access-control check to the legacy matcher, then
     * enriches with structured capability data where available.
     *
     * @return list<AgentCapabilityDescriptor>
     */
    public function delegableDescriptorsForCurrentUser(): array
    {
        $legacyAgents = $this->legacyMatcher->discoverDelegableAgentsForCurrentUser();

        $descriptors = [];

        foreach ($legacyAgents as $legacyAgent) {
            $descriptor = $this->descriptorFor($legacyAgent['employee_id']);

            if ($descriptor !== null) {
                $descriptors[] = $descriptor;
            }
        }

        return $descriptors;
    }

    /**
     * Build a descriptor from an Employee model.
     *
     * Reads structured capabilities from Employee.metadata['ai_capabilities']
     * when present. Falls back to designation/job_description for the
     * display summary.
     */
    private function buildDescriptor(Employee $employee): AgentCapabilityDescriptor
    {
        $capabilities = $this->extractCapabilities($employee);
        $displaySummary = $this->buildDisplaySummary($employee);

        return new AgentCapabilityDescriptor(
            employeeId: $employee->id,
            name: $employee->displayName(),
            domains: $capabilities['domains'] ?? [],
            taskTypes: $capabilities['task_types'] ?? [],
            specialties: $capabilities['specialties'] ?? [],
            toolAccess: $capabilities['tool_access'] ?? [],
            requiresHumanReview: $capabilities['requires_human_review'] ?? false,
            displaySummary: $displaySummary,
            meta: $capabilities['meta'] ?? [],
        );
    }

    /**
     * Extract structured capabilities from Employee metadata.
     *
     * @return array{domains?: list<string>, task_types?: list<string>, specialties?: list<string>, tool_access?: list<string>, requires_human_review?: bool, meta?: array<string, mixed>}
     */
    private function extractCapabilities(Employee $employee): array
    {
        $metadata = $employee->metadata;

        if (! is_array($metadata) || ! isset($metadata[self::CAPABILITY_META_KEY])) {
            return [];
        }

        $capabilities = $metadata[self::CAPABILITY_META_KEY];

        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * Build a display summary from Employee designation and job description.
     */
    private function buildDisplaySummary(Employee $employee): string
    {
        $designation = trim((string) ($employee->designation ?? ''));
        $description = trim((string) ($employee->job_description ?? ''));

        if ($designation === '' && $description === '') {
            return __('General Agent');
        }

        if ($designation !== '' && $description !== '') {
            return $designation.' — '.$description;
        }

        return $designation !== '' ? $designation : $description;
    }
}
