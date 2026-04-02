<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

/**
 * Structured capability descriptor for an agent.
 *
 * Replaces the free-text capability summary as the primary routing
 * input. Free-text summaries remain as display fields but are no
 * longer the routing truth.
 */
final readonly class AgentCapabilityDescriptor
{
    /**
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $name  Agent display name
     * @param  list<string>  $domains  Capability domains (e.g. 'it_support', 'code_review', 'data_analysis')
     * @param  list<string>  $taskTypes  Supported task type discriminators
     * @param  list<string>  $specialties  Declared specialties for finer-grained matching
     * @param  list<string>  $toolAccess  Tool names this agent has access to
     * @param  bool  $requiresHumanReview  Whether tasks routed to this agent need human review
     * @param  string|null  $displaySummary  Free-text summary for UI display (not for routing)
     * @param  array<string, mixed>  $meta  Additional metadata (confidence, maturity, etc.)
     */
    public function __construct(
        public int $employeeId,
        public string $name,
        public array $domains = [],
        public array $taskTypes = [],
        public array $specialties = [],
        public array $toolAccess = [],
        public bool $requiresHumanReview = false,
        public ?string $displaySummary = null,
        public array $meta = [],
    ) {}

    /**
     * Whether this agent declares any structured capability data.
     *
     * Agents without structured data fall back to legacy keyword matching.
     */
    public function hasStructuredCapabilities(): bool
    {
        return $this->domains !== [] || $this->taskTypes !== [] || $this->specialties !== [];
    }

    /**
     * Whether the agent declares support for a specific domain.
     */
    public function supportsDomain(string $domain): bool
    {
        return in_array($domain, $this->domains, true);
    }

    /**
     * Whether the agent declares support for a specific task type.
     */
    public function supportsTaskType(string $taskType): bool
    {
        return in_array($taskType, $this->taskTypes, true);
    }

    /**
     * Serialize for API and audit.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'employee_id' => $this->employeeId,
            'name' => $this->name,
            'domains' => $this->domains !== [] ? $this->domains : null,
            'task_types' => $this->taskTypes !== [] ? $this->taskTypes : null,
            'specialties' => $this->specialties !== [] ? $this->specialties : null,
            'tool_access' => $this->toolAccess !== [] ? $this->toolAccess : null,
            'requires_human_review' => $this->requiresHumanReview ?: null,
            'display_summary' => $this->displaySummary,
            'meta' => $this->meta !== [] ? $this->meta : null,
        ], fn (mixed $v): bool => $v !== null);
    }
}
