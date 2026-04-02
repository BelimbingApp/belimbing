<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\DTO\Orchestration\RoutingDecision;
use App\Modules\Core\AI\DTO\Orchestration\RoutingRequest;
use App\Modules\Core\AI\Enums\RoutingTarget;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Modules\Core\AI\Services\Orchestration\OrchestrationPolicyService;
use App\Modules\Core\AI\Services\Orchestration\TaskRoutingService;

const ROUTING_TASK_REVIEW_CODE = 'Review the authentication module code';
const ROUTING_TASK_DEPLOY_INFRA = 'Deploy infrastructure updates to staging';

const ROUTING_AGENT_KODI = 'Kodi';
const ROUTING_AGENT_OPS = 'OpsBot';
const ROUTING_AGENT_GENERIC = 'GenericBot';
const ROUTING_DOMAIN_CODE_REVIEW = 'code_review';
const ROUTING_DOMAIN_INFRASTRUCTURE = 'infrastructure';
const ROUTING_TASK_TYPE_CODE_REVIEW = 'code_review';
const ROUTING_SPECIALTY_AUTH = 'authentication';
const ROUTING_METHOD_EXPLICIT = 'explicit_preference';
const ROUTING_METHOD_STRUCTURED = 'structured_capability';
const ROUTING_METHOD_KEYWORD = 'keyword_fallback';

function makeRoutingService(
    ?AgentCapabilityCatalog $catalog = null,
    ?OrchestrationPolicyService $policy = null,
): TaskRoutingService {
    return new TaskRoutingService(
        $catalog ?? Mockery::mock(AgentCapabilityCatalog::class),
        $policy ?? new OrchestrationPolicyService,
    );
}

/**
 * Build an AgentCapabilityDescriptor with structured capabilities.
 *
 * @param  list<string>  $domains
 * @param  list<string>  $taskTypes
 * @param  list<string>  $specialties
 */
function makeStructuredDescriptor(
    int $employeeId,
    string $name,
    array $domains = [],
    array $taskTypes = [],
    array $specialties = [],
    ?string $displaySummary = null,
): AgentCapabilityDescriptor {
    return new AgentCapabilityDescriptor(
        employeeId: $employeeId,
        name: $name,
        domains: $domains,
        taskTypes: $taskTypes,
        specialties: $specialties,
        displaySummary: $displaySummary,
    );
}

/**
 * Build an AgentCapabilityDescriptor without structured capabilities (keyword-only fallback).
 */
function makeUnstructuredDescriptor(int $employeeId, string $name, string $displaySummary): AgentCapabilityDescriptor
{
    return new AgentCapabilityDescriptor(
        employeeId: $employeeId,
        name: $name,
        displaySummary: $displaySummary,
    );
}

// --- Explicit preference routing ---

describe('explicit agent preference', function (): void {
    it('routes to the preferred agent when available and policy allows', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('descriptorFor')
            ->with(2)
            ->andReturn(makeStructuredDescriptor(2, ROUTING_AGENT_KODI));

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
            preferredAgentId: 2,
        );

        $decision = $service->route($request);

        expect($decision)->toBeInstanceOf(RoutingDecision::class)
            ->and($decision->target)->toBe(RoutingTarget::Agent)
            ->and($decision->agentEmployeeId)->toBe(2)
            ->and($decision->agentName)->toBe(ROUTING_AGENT_KODI)
            ->and($decision->confidenceScore)->toBe(100)
            ->and($decision->meta['routing_method'])->toBe(ROUTING_METHOD_EXPLICIT);
    });

    it('falls back to local when preferred agent is unavailable', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('descriptorFor')
            ->with(999)
            ->andReturn(null);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
            preferredAgentId: 999,
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Local)
            ->and($decision->reasons[0])->toContain('999')
            ->and($decision->reasons[0])->toContain('not available');
    });

    it('falls back to local when policy denies delegation to preferred agent', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        // Self-delegation is denied by policy (same ID)
        $catalog->shouldReceive('descriptorFor')
            ->with(1)
            ->andReturn(makeStructuredDescriptor(1, 'Self'));

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
            preferredAgentId: 1,
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Local)
            ->and($decision->reasons[0])->toContain('Policy does not allow');
    });
});

// --- Structured capability matching ---

describe('structured capability matching', function (): void {
    it('matches agent by task type with high confidence', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeStructuredDescriptor(
                    2, ROUTING_AGENT_KODI,
                    domains: [ROUTING_DOMAIN_CODE_REVIEW],
                    taskTypes: [ROUTING_TASK_TYPE_CODE_REVIEW],
                    specialties: [ROUTING_SPECIALTY_AUTH],
                ),
            ]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
            taskType: ROUTING_TASK_TYPE_CODE_REVIEW,
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Agent)
            ->and($decision->agentEmployeeId)->toBe(2)
            ->and($decision->confidenceScore)->toBeGreaterThanOrEqual(40)
            ->and($decision->meta['routing_method'])->toBe(ROUTING_METHOD_STRUCTURED);
    });

    it('matches agent by domain constraint', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeStructuredDescriptor(
                    3, ROUTING_AGENT_OPS,
                    domains: [ROUTING_DOMAIN_INFRASTRUCTURE],
                ),
            ]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_DEPLOY_INFRA,
            requestingEmployeeId: 1,
            constraints: ['domains' => [ROUTING_DOMAIN_INFRASTRUCTURE]],
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Agent)
            ->and($decision->agentEmployeeId)->toBe(3)
            ->and($decision->meta['routing_method'])->toBe(ROUTING_METHOD_STRUCTURED);

        $domainReason = collect($decision->reasons)->first(
            fn (string $r): bool => str_contains($r, ROUTING_DOMAIN_INFRASTRUCTURE),
        );
        expect($domainReason)->not->toBeNull();
    });

    it('selects the highest-scoring agent when multiple agents match', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeStructuredDescriptor(
                    2, ROUTING_AGENT_KODI,
                    domains: [ROUTING_DOMAIN_CODE_REVIEW],
                    taskTypes: [ROUTING_TASK_TYPE_CODE_REVIEW],
                    specialties: [ROUTING_SPECIALTY_AUTH],
                ),
                makeStructuredDescriptor(
                    3, ROUTING_AGENT_OPS,
                    domains: [ROUTING_DOMAIN_INFRASTRUCTURE],
                    taskTypes: ['deploy'],
                ),
            ]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
            taskType: ROUTING_TASK_TYPE_CODE_REVIEW,
            constraints: ['domains' => [ROUTING_DOMAIN_CODE_REVIEW]],
        );

        $decision = $service->route($request);

        // Kodi should win: task type match (40) + domain constraint match (30) + specialty keyword 'authentication' in task (15)
        expect($decision->target)->toBe(RoutingTarget::Agent)
            ->and($decision->agentEmployeeId)->toBe(2)
            ->and($decision->agentName)->toBe(ROUTING_AGENT_KODI);
    });

    it('skips agents without structured capabilities during structured matching', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeUnstructuredDescriptor(5, ROUTING_AGENT_GENERIC, 'General operations bot'),
                makeStructuredDescriptor(
                    3, ROUTING_AGENT_OPS,
                    domains: [ROUTING_DOMAIN_INFRASTRUCTURE],
                    taskTypes: ['deploy'],
                ),
            ]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_DEPLOY_INFRA,
            requestingEmployeeId: 1,
            taskType: 'deploy',
            constraints: ['domains' => [ROUTING_DOMAIN_INFRASTRUCTURE]],
        );

        $decision = $service->route($request);

        // OpsBot should match via structured capabilities; GenericBot has none
        expect($decision->target)->toBe(RoutingTarget::Agent)
            ->and($decision->agentEmployeeId)->toBe(3)
            ->and($decision->meta['routing_method'])->toBe(ROUTING_METHOD_STRUCTURED);
    });
});

// --- Keyword fallback matching ---

describe('keyword fallback matching', function (): void {
    it('falls back to keyword matching when no structured capabilities exist', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeUnstructuredDescriptor(
                    5,
                    ROUTING_AGENT_GENERIC,
                    'Code review specialist — handles authentication and authorization modules',
                ),
            ]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Agent)
            ->and($decision->agentEmployeeId)->toBe(5)
            ->and($decision->meta['routing_method'])->toBe(ROUTING_METHOD_KEYWORD)
            ->and($decision->confidenceScore)->toBeLessThanOrEqual(80);
    });

    it('caps keyword match confidence at 80', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        // Summary repeats many task words for high keyword overlap
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeUnstructuredDescriptor(
                    5,
                    ROUTING_AGENT_GENERIC,
                    'review authentication module code deploy infrastructure updates staging',
                ),
            ]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
        );

        $decision = $service->route($request);

        expect($decision->confidenceScore)->toBeLessThanOrEqual(80);
    });
});

// --- Local fallback ---

describe('local execution fallback', function (): void {
    it('routes locally when no delegable agents exist', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([]);

        $service = makeRoutingService($catalog);

        $request = new RoutingRequest(
            task: ROUTING_TASK_REVIEW_CODE,
            requestingEmployeeId: 1,
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Local)
            ->and($decision->reasons[0])->toContain('No delegable agents');
    });

    it('routes locally when no agent matches by any method', function (): void {
        $catalog = Mockery::mock(AgentCapabilityCatalog::class);
        $catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->andReturn([
                makeUnstructuredDescriptor(
                    5,
                    ROUTING_AGENT_GENERIC,
                    'Culinary arts and baking techniques',
                ),
            ]);

        $service = makeRoutingService($catalog);

        // Task with zero keyword overlap with the agent summary
        $request = new RoutingRequest(
            task: 'xyz quantum flux',
            requestingEmployeeId: 1,
        );

        $decision = $service->route($request);

        expect($decision->target)->toBe(RoutingTarget::Local)
            ->and($decision->reasons)->not->toBeEmpty();
    });
});
