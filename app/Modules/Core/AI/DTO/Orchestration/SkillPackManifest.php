<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

use App\Modules\Core\AI\Enums\SkillPackStatus;

/**
 * Manifest describing a registered skill pack.
 *
 * A skill pack bundles prompts, tool bindings, references, readiness
 * checks, and optional hook registrations into a named, versioned unit.
 * The manifest is the contract — the runtime resolves packs through it.
 */
final readonly class SkillPackManifest
{
    /**
     * @param  string  $id  Unique pack identifier (e.g. 'blb.code-review')
     * @param  string  $version  Semantic version string
     * @param  string  $name  Human-readable pack name
     * @param  string  $description  What this pack provides
     * @param  string|null  $owner  Module or team that owns this pack
     * @param  list<string>  $applicableAgentIds  Agent employee IDs this pack applies to (empty = all)
     * @param  list<string>  $applicableRoles  Roles this pack applies to (empty = all)
     * @param  list<SkillPackPromptResource>  $promptResources  Prompt sections bundled in this pack
     * @param  list<string>  $toolBindings  Tool names this pack makes available or requires
     * @param  list<SkillPackReference>  $references  Reference documents bundled in this pack
     * @param  list<string>  $readinessChecks  Readiness check descriptions
     * @param  list<SkillPackHookBinding>  $hookBindings  Hook registrations bundled in this pack
     * @param  SkillPackStatus  $status  Current pack status
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public string $description,
        public ?string $owner = null,
        public array $applicableAgentIds = [],
        public array $applicableRoles = [],
        public array $promptResources = [],
        public array $toolBindings = [],
        public array $references = [],
        public array $readinessChecks = [],
        public array $hookBindings = [],
        public SkillPackStatus $status = SkillPackStatus::Ready,
    ) {}

    /**
     * Whether this pack applies to the given agent employee ID.
     *
     * An empty applicableAgentIds list means the pack applies to all agents.
     */
    public function appliesTo(int $employeeId): bool
    {
        if ($this->applicableAgentIds === []) {
            return true;
        }

        return in_array((string) $employeeId, $this->applicableAgentIds, true);
    }

    /**
     * Whether the pack is currently available for resolution.
     */
    public function isAvailable(): bool
    {
        return $this->status->isAvailable();
    }

    /**
     * Serialize for persistence or API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'name' => $this->name,
            'description' => $this->description,
            'owner' => $this->owner,
            'applicable_agent_ids' => $this->applicableAgentIds,
            'applicable_roles' => $this->applicableRoles,
            'prompt_resources' => array_map(fn (SkillPackPromptResource $r): array => $r->toArray(), $this->promptResources),
            'tool_bindings' => $this->toolBindings,
            'references' => array_map(fn (SkillPackReference $r): array => $r->toArray(), $this->references),
            'readiness_checks' => $this->readinessChecks,
            'hook_bindings' => array_map(fn (SkillPackHookBinding $b): array => $b->toArray(), $this->hookBindings),
            'status' => $this->status->value,
        ];
    }
}
