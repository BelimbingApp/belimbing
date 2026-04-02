<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Orchestration\SkillPackHookBinding;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Enums\SkillPackStatus;
use App\Modules\Core\AI\Services\Orchestration\SkillPackRegistry;

const SKILL_REG_PACK_ID = 'blb.test-registry-pack';
const SKILL_REG_PACK_NAME = 'Registry Test Pack';
const SKILL_REG_PACK_DESC = 'Pack for registry tests';
const SKILL_REG_VERSION = '1.0.0';
const SKILL_REG_ALT_PACK_ID = 'blb.alt-registry-pack';
const SKILL_REG_AGENT_ONLY_PACK_ID = 'blb.agent-only-pack';

function makeSkillPackRegistry(): SkillPackRegistry
{
    return new SkillPackRegistry;
}

function makeTestManifest(
    string $id = SKILL_REG_PACK_ID,
    SkillPackStatus $status = SkillPackStatus::Ready,
    array $applicableAgentIds = [],
    array $promptResources = [],
    array $toolBindings = [],
    array $references = [],
    array $hookBindings = [],
): SkillPackManifest {
    return new SkillPackManifest(
        id: $id,
        version: SKILL_REG_VERSION,
        name: SKILL_REG_PACK_NAME,
        description: SKILL_REG_PACK_DESC,
        applicableAgentIds: $applicableAgentIds,
        promptResources: $promptResources,
        toolBindings: $toolBindings,
        references: $references,
        hookBindings: $hookBindings,
        status: $status,
    );
}

// --- register / find / has ---

it('registers a pack and finds it by ID', function (): void {
    $registry = makeSkillPackRegistry();
    $manifest = makeTestManifest();

    $registry->register($manifest);

    expect($registry->has(SKILL_REG_PACK_ID))->toBeTrue()
        ->and($registry->find(SKILL_REG_PACK_ID))->toBe($manifest)
        ->and($registry->count())->toBe(1);
});

it('rejects duplicate pack registration', function (): void {
    $registry = makeSkillPackRegistry();
    $manifest = makeTestManifest();

    $registry->register($manifest);
    $registry->register($manifest);
})->throws(InvalidArgumentException::class, 'already registered');

it('returns null for unknown pack ID', function (): void {
    $registry = makeSkillPackRegistry();

    expect($registry->find('nonexistent'))->toBeNull()
        ->and($registry->has('nonexistent'))->toBeFalse();
});

// --- unregister ---

it('unregisters a pack and returns true', function (): void {
    $registry = makeSkillPackRegistry();
    $registry->register(makeTestManifest());

    expect($registry->unregister(SKILL_REG_PACK_ID))->toBeTrue()
        ->and($registry->has(SKILL_REG_PACK_ID))->toBeFalse()
        ->and($registry->count())->toBe(0);
});

it('returns false when unregistering a non-existent pack', function (): void {
    $registry = makeSkillPackRegistry();

    expect($registry->unregister('nonexistent'))->toBeFalse();
});

// --- all / forAgent / availableForAgent ---

it('lists all registered packs', function (): void {
    $registry = makeSkillPackRegistry();
    $pack1 = makeTestManifest(id: SKILL_REG_PACK_ID);
    $pack2 = makeTestManifest(id: SKILL_REG_ALT_PACK_ID);

    $registry->register($pack1);
    $registry->register($pack2);

    $all = $registry->all();

    expect($all)->toHaveCount(2)
        ->and(array_map(fn ($m) => $m->id, $all))->toContain(SKILL_REG_PACK_ID, SKILL_REG_ALT_PACK_ID);
});

it('filters packs by agent applicability', function (): void {
    $registry = makeSkillPackRegistry();

    $universal = makeTestManifest(id: SKILL_REG_PACK_ID, applicableAgentIds: []);
    $agentSpecific = makeTestManifest(id: SKILL_REG_AGENT_ONLY_PACK_ID, applicableAgentIds: ['5']);

    $registry->register($universal);
    $registry->register($agentSpecific);

    $forAgent5 = $registry->forAgent(5);
    $forAgent99 = $registry->forAgent(99);

    expect($forAgent5)->toHaveCount(2)
        ->and($forAgent99)->toHaveCount(1)
        ->and($forAgent99[0]->id)->toBe(SKILL_REG_PACK_ID);
});

it('filters available packs by agent and readiness', function (): void {
    $registry = makeSkillPackRegistry();

    $ready = makeTestManifest(id: SKILL_REG_PACK_ID, status: SkillPackStatus::Ready);
    $disabled = makeTestManifest(id: SKILL_REG_ALT_PACK_ID, status: SkillPackStatus::Disabled);

    $registry->register($ready);
    $registry->register($disabled);

    $available = $registry->availableForAgent(1);

    expect($available)->toHaveCount(1)
        ->and($available[0]->id)->toBe(SKILL_REG_PACK_ID);
});

// --- verify ---

it('verifies a pack with prompt resources as ready', function (): void {
    $registry = makeSkillPackRegistry();

    $manifest = makeTestManifest(
        promptResources: [
            new SkillPackPromptResource(label: 'test', content: 'Test prompt'),
        ],
    );

    $registry->register($manifest);

    $result = $registry->verify(SKILL_REG_PACK_ID);

    expect($result['status'])->toBe(SkillPackStatus::Ready)
        ->and($result['checks'])->not->toBeEmpty()
        ->and($result['checks'][0]['passed'])->toBeTrue();
});

it('verifies a pack with tool bindings as ready', function (): void {
    $registry = makeSkillPackRegistry();

    $manifest = makeTestManifest(toolBindings: ['some_tool']);
    $registry->register($manifest);

    $result = $registry->verify(SKILL_REG_PACK_ID);

    expect($result['status'])->toBe(SkillPackStatus::Ready);
});

it('marks a pack with no resources as degraded', function (): void {
    $registry = makeSkillPackRegistry();

    $manifest = makeTestManifest();
    $registry->register($manifest);

    $result = $registry->verify(SKILL_REG_PACK_ID);

    expect($result['status'])->toBe(SkillPackStatus::Degraded)
        ->and($result['checks'][0]['passed'])->toBeFalse()
        ->and($result['checks'][0]['reason'])->toContain('no prompt resources');
});

it('detects missing hook class during verification', function (): void {
    $registry = makeSkillPackRegistry();

    $manifest = makeTestManifest(
        toolBindings: ['some_tool'],
        hookBindings: [
            new SkillPackHookBinding(
                stage: HookStage::PreContextBuild,
                hookClass: 'App\\NonExistent\\HookClass',
            ),
        ],
    );

    $registry->register($manifest);

    $result = $registry->verify(SKILL_REG_PACK_ID);

    expect($result['status'])->toBe(SkillPackStatus::Degraded);

    $hookCheck = collect($result['checks'])->first(
        fn (array $check) => str_contains($check['check'], 'hook_class_exists'),
    );

    expect($hookCheck)->not->toBeNull()
        ->and($hookCheck['passed'])->toBeFalse()
        ->and($hookCheck['reason'])->toContain('NonExistent');
});

it('preserves disabled status during verification even if structurally valid', function (): void {
    $registry = makeSkillPackRegistry();

    $manifest = makeTestManifest(
        status: SkillPackStatus::Disabled,
        toolBindings: ['some_tool'],
    );

    $registry->register($manifest);

    $result = $registry->verify(SKILL_REG_PACK_ID);

    expect($result['status'])->toBe(SkillPackStatus::Disabled);
});

it('returns degraded status for a non-existent pack', function (): void {
    $registry = makeSkillPackRegistry();

    $result = $registry->verify('nonexistent');

    expect($result['status'])->toBe(SkillPackStatus::Disabled)
        ->and($result['checks'][0]['passed'])->toBeFalse()
        ->and($result['checks'][0]['reason'])->toContain('not found');
});
