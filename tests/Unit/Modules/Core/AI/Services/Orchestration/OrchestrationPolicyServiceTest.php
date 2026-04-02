<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SpawnEnvelope;
use App\Modules\Core\AI\Enums\SkillPackStatus;
use App\Modules\Core\AI\Services\Orchestration\OrchestrationPolicyService;

const ORCH_POLICY_TASK = 'Review the quarterly report';
const ORCH_POLICY_PACK_ID = 'blb.test-pack';
const ORCH_POLICY_PACK_NAME = 'Test Pack';
const ORCH_POLICY_PACK_DESC = 'A test pack for policy tests';

function makeOrchestrationPolicyService(): OrchestrationPolicyService
{
    return new OrchestrationPolicyService;
}

// --- canDelegate ---

it('allows delegation between different agents', function (): void {
    $policy = makeOrchestrationPolicyService();

    expect($policy->canDelegate(1, 2))->toBeTrue()
        ->and($policy->canDelegate(2, 1))->toBeTrue()
        ->and($policy->canDelegate(10, 20))->toBeTrue();
});

it('prevents self-delegation', function (): void {
    $policy = makeOrchestrationPolicyService();

    expect($policy->canDelegate(1, 1))->toBeFalse()
        ->and($policy->canDelegate(5, 5))->toBeFalse();
});

// --- canSpawn ---

it('allows spawning a child session for a different agent', function (): void {
    $policy = makeOrchestrationPolicyService();

    $envelope = new SpawnEnvelope(
        parentEmployeeId: 1,
        childEmployeeId: 2,
        task: ORCH_POLICY_TASK,
    );

    expect($policy->canSpawn($envelope))->toBeTrue();
});

it('prevents self-spawn to avoid infinite recursion', function (): void {
    $policy = makeOrchestrationPolicyService();

    $envelope = new SpawnEnvelope(
        parentEmployeeId: 1,
        childEmployeeId: 1,
        task: ORCH_POLICY_TASK,
    );

    expect($policy->canSpawn($envelope))->toBeFalse();
});

// --- isSkillPackApplicable ---

it('allows an available skill pack applicable to the agent', function (): void {
    $policy = makeOrchestrationPolicyService();

    $manifest = new SkillPackManifest(
        id: ORCH_POLICY_PACK_ID,
        version: '1.0.0',
        name: ORCH_POLICY_PACK_NAME,
        description: ORCH_POLICY_PACK_DESC,
        applicableAgentIds: ['5'],
        status: SkillPackStatus::Ready,
    );

    expect($policy->isSkillPackApplicable($manifest, 5))->toBeTrue();
});

it('allows a universal skill pack with empty applicableAgentIds', function (): void {
    $policy = makeOrchestrationPolicyService();

    $manifest = new SkillPackManifest(
        id: ORCH_POLICY_PACK_ID,
        version: '1.0.0',
        name: ORCH_POLICY_PACK_NAME,
        description: ORCH_POLICY_PACK_DESC,
        applicableAgentIds: [],
        status: SkillPackStatus::Ready,
    );

    expect($policy->isSkillPackApplicable($manifest, 99))->toBeTrue();
});

it('rejects a disabled skill pack even if agent matches', function (): void {
    $policy = makeOrchestrationPolicyService();

    $manifest = new SkillPackManifest(
        id: ORCH_POLICY_PACK_ID,
        version: '1.0.0',
        name: ORCH_POLICY_PACK_NAME,
        description: ORCH_POLICY_PACK_DESC,
        applicableAgentIds: ['5'],
        status: SkillPackStatus::Disabled,
    );

    expect($policy->isSkillPackApplicable($manifest, 5))->toBeFalse();
});

it('rejects a degraded skill pack', function (): void {
    $policy = makeOrchestrationPolicyService();

    $manifest = new SkillPackManifest(
        id: ORCH_POLICY_PACK_ID,
        version: '1.0.0',
        name: ORCH_POLICY_PACK_NAME,
        description: ORCH_POLICY_PACK_DESC,
        status: SkillPackStatus::Degraded,
    );

    expect($policy->isSkillPackApplicable($manifest, 1))->toBeFalse();
});

it('rejects a ready skill pack when agent is not in applicableAgentIds', function (): void {
    $policy = makeOrchestrationPolicyService();

    $manifest = new SkillPackManifest(
        id: ORCH_POLICY_PACK_ID,
        version: '1.0.0',
        name: ORCH_POLICY_PACK_NAME,
        description: ORCH_POLICY_PACK_DESC,
        applicableAgentIds: ['5', '10'],
        status: SkillPackStatus::Ready,
    );

    expect($policy->isSkillPackApplicable($manifest, 99))->toBeFalse();
});

// --- hasRoutableCapabilities ---

it('recognizes structured capabilities when domains are present', function (): void {
    $policy = makeOrchestrationPolicyService();

    $descriptor = new AgentCapabilityDescriptor(
        employeeId: 1,
        name: 'Agent A',
        domains: ['it_support'],
    );

    expect($policy->hasRoutableCapabilities($descriptor))->toBeTrue();
});

it('recognizes structured capabilities when task types are present', function (): void {
    $policy = makeOrchestrationPolicyService();

    $descriptor = new AgentCapabilityDescriptor(
        employeeId: 1,
        name: 'Agent A',
        taskTypes: ['resolve_ticket'],
    );

    expect($policy->hasRoutableCapabilities($descriptor))->toBeTrue();
});

it('recognizes structured capabilities when specialties are present', function (): void {
    $policy = makeOrchestrationPolicyService();

    $descriptor = new AgentCapabilityDescriptor(
        employeeId: 1,
        name: 'Agent A',
        specialties: ['database_migration'],
    );

    expect($policy->hasRoutableCapabilities($descriptor))->toBeTrue();
});

it('rejects agents without structured capabilities', function (): void {
    $policy = makeOrchestrationPolicyService();

    $descriptor = new AgentCapabilityDescriptor(
        employeeId: 1,
        name: 'Agent A',
        displaySummary: 'Just a free-text bio, no structured data',
    );

    expect($policy->hasRoutableCapabilities($descriptor))->toBeFalse();
});

// --- maxSpawnDepth ---

it('returns a positive spawn depth limit', function (): void {
    $policy = makeOrchestrationPolicyService();

    expect($policy->maxSpawnDepth())->toBe(3);
});
