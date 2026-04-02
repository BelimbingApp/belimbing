<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

/**
 * Inbound request for task routing.
 *
 * Captures everything the routing engine needs to decide where work
 * should go: task description, requesting context, and any explicit
 * constraints the caller wants to impose.
 */
final readonly class RoutingRequest
{
    /**
     * @param  string  $task  Free-text task description
     * @param  int  $requestingEmployeeId  Employee (agent) requesting the routing
     * @param  int|null  $actingForUserId  Human user on whose behalf routing is happening
     * @param  int|null  $preferredAgentId  Explicit agent preference (bypasses auto-match)
     * @param  string|null  $taskType  Task type discriminator (e.g. 'resolve_ticket')
     * @param  array<string, mixed>  $constraints  Caller-imposed constraints (domains, latency, trust)
     * @param  string|null  $sourceContext  Where the routing request originated (tool, slash command, API)
     */
    public function __construct(
        public string $task,
        public int $requestingEmployeeId,
        public ?int $actingForUserId = null,
        public ?int $preferredAgentId = null,
        public ?string $taskType = null,
        public array $constraints = [],
        public ?string $sourceContext = null,
    ) {}

    /**
     * Whether the caller explicitly requested a specific agent.
     */
    public function hasPreferredAgent(): bool
    {
        return $this->preferredAgentId !== null;
    }
}
