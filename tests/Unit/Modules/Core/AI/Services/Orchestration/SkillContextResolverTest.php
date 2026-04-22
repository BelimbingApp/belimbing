<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Orchestration\SkillPackHookBinding;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackReference;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Enums\SkillPackStatus;
use App\Modules\Core\AI\Services\Orchestration\OrchestrationPolicyService;
use App\Modules\Core\AI\Services\Orchestration\SkillContextResolver;
use App\Modules\Core\AI\Services\Orchestration\SkillPackRegistry;

const SKILL_CTX_UNIVERSAL_PACK_ID = 'blb.ctx-universal';
const SKILL_CTX_AGENT_PACK_ID = 'blb.ctx-agent-specific';
const SKILL_CTX_DISABLED_PACK_ID = 'blb.ctx-disabled';
const SKILL_CTX_PACK_VERSION = '1.0.0';
const SKILL_CTX_PACK_NAME = 'Context Resolver Test';
const SKILL_CTX_PACK_DESC = 'Pack for context resolver tests';
const SKILL_CTX_AGENT_ID = 5;

function makeSkillContextResolver(?SkillPackRegistry $registry = null): SkillContextResolver
{
    $registry ??= new SkillPackRegistry;

    return new SkillContextResolver($registry, new OrchestrationPolicyService);
}

function makeContextTestManifest(
    string $id,
    SkillPackStatus $status = SkillPackStatus::Ready,
    array $applicableAgentIds = [],
    array $promptResources = [],
    array $toolBindings = [],
    array $references = [],
    array $hookBindings = [],
): SkillPackManifest {
    return new SkillPackManifest(
        id: $id,
        version: SKILL_CTX_PACK_VERSION,
        name: SKILL_CTX_PACK_NAME,
        description: SKILL_CTX_PACK_DESC,
        applicableAgentIds: $applicableAgentIds,
        promptResources: $promptResources,
        toolBindings: $toolBindings,
        references: $references,
        hookBindings: $hookBindings,
        status: $status,
    );
}

// --- resolve (empty) ---

it('returns empty resolution when no packs are registered', function (): void {
    $resolver = makeSkillContextResolver();
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->hasContent())->toBeFalse()
        ->and($resolution->packCount())->toBe(0)
        ->and($resolution->assembledPrompt())->toBe('')
        ->and($resolution->toolBindings)->toBeEmpty()
        ->and($resolution->references)->toBeEmpty();
});

// --- resolve with applicable packs ---

it('resolves a universal pack for any agent', function (): void {
    $registry = new SkillPackRegistry;
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        promptResources: [
            new SkillPackPromptResource(label: 'intro', content: 'Welcome to Belimbing.', order: 10),
        ],
        toolBindings: ['guide_search'],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->hasContent())->toBeTrue()
        ->and($resolution->resolvedPackIds)->toBe([SKILL_CTX_UNIVERSAL_PACK_ID])
        ->and($resolution->assembledPrompt())->toContain('Welcome to Belimbing')
        ->and($resolution->toolBindings)->toBe(['guide_search']);
});

it('excludes packs not applicable to the agent', function (): void {
    $registry = new SkillPackRegistry;
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_AGENT_PACK_ID,
        applicableAgentIds: ['99'],
        promptResources: [
            new SkillPackPromptResource(label: 'restricted', content: 'Agent 99 only.'),
        ],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->hasContent())->toBeFalse();
});

it('excludes disabled packs even if agent matches', function (): void {
    $registry = new SkillPackRegistry;
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_DISABLED_PACK_ID,
        status: SkillPackStatus::Disabled,
        promptResources: [
            new SkillPackPromptResource(label: 'disabled', content: 'Should not appear.'),
        ],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->hasContent())->toBeFalse();
});

// --- merge behavior ---

it('merges prompt resources from multiple packs sorted by order', function (): void {
    $registry = new SkillPackRegistry;

    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        promptResources: [
            new SkillPackPromptResource(label: 'second', content: 'Second section.', order: 200),
        ],
    ));
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_AGENT_PACK_ID,
        promptResources: [
            new SkillPackPromptResource(label: 'first', content: 'First section.', order: 100),
        ],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->packCount())->toBe(2)
        ->and($resolution->promptResources)->toHaveCount(2)
        ->and($resolution->promptResources[0]->label)->toBe('first')
        ->and($resolution->promptResources[1]->label)->toBe('second')
        ->and($resolution->assembledPrompt())->toBe("First section.\n\nSecond section.");
});

it('deduplicates tool bindings across packs', function (): void {
    $registry = new SkillPackRegistry;

    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        toolBindings: ['guide_search', 'bash'],
    ));
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_AGENT_PACK_ID,
        toolBindings: ['guide_search', 'web_search'],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->toolBindings)->toHaveCount(3)
        ->and($resolution->toolBindings)->toContain('guide_search', 'bash', 'web_search');
});

it('merges references from multiple packs', function (): void {
    $registry = new SkillPackRegistry;

    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        references: [new SkillPackReference(title: 'Ref A', path: 'docs/a.md')],
    ));
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_AGENT_PACK_ID,
        references: [new SkillPackReference(title: 'Ref B', path: 'docs/b.md')],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->references)->toHaveCount(2);
});

it('merges hook bindings from multiple packs sorted by priority', function (): void {
    $registry = new SkillPackRegistry;

    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        hookBindings: [
            new SkillPackHookBinding(stage: HookStage::PreContextBuild, hookClass: 'App\\Hook\\Late', priority: 200),
        ],
    ));
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_AGENT_PACK_ID,
        hookBindings: [
            new SkillPackHookBinding(stage: HookStage::PreContextBuild, hookClass: 'App\\Hook\\Early', priority: 50),
        ],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    expect($resolution->hookBindings)->toHaveCount(2)
        ->and($resolution->hookBindings[0]->hookClass)->toBe('App\\Hook\\Early')
        ->and($resolution->hookBindings[1]->hookClass)->toBe('App\\Hook\\Late');
});

// --- applicablePackIds ---

it('lists applicable pack IDs without full merge', function (): void {
    $registry = new SkillPackRegistry;

    $registry->register(makeContextTestManifest(id: SKILL_CTX_UNIVERSAL_PACK_ID));
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_AGENT_PACK_ID,
        applicableAgentIds: ['99'],
    ));

    $resolver = makeSkillContextResolver($registry);

    expect($resolver->applicablePackIds(SKILL_CTX_AGENT_ID))->toBe([SKILL_CTX_UNIVERSAL_PACK_ID])
        ->and($resolver->applicablePackIds(99))->toContain(SKILL_CTX_UNIVERSAL_PACK_ID, SKILL_CTX_AGENT_PACK_ID);
});

// --- resolveForTask ---

it('resolveForTask delegates to resolve for now', function (): void {
    $registry = new SkillPackRegistry;
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        toolBindings: ['guide_search'],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolveForTask(SKILL_CTX_AGENT_ID, 'Review the code', 'code_review');

    expect($resolution->hasContent())->toBeTrue()
        ->and($resolution->resolvedPackIds)->toBe([SKILL_CTX_UNIVERSAL_PACK_ID]);
});

// --- SkillResolution.toArray ---

it('serializes resolution for diagnostics', function (): void {
    $registry = new SkillPackRegistry;
    $registry->register(makeContextTestManifest(
        id: SKILL_CTX_UNIVERSAL_PACK_ID,
        promptResources: [new SkillPackPromptResource(label: 'test', content: 'Content')],
        toolBindings: ['guide_search'],
        references: [new SkillPackReference(title: 'Ref', path: 'docs/ref.md')],
    ));

    $resolver = makeSkillContextResolver($registry);
    $resolution = $resolver->resolve(SKILL_CTX_AGENT_ID);

    $array = $resolution->toArray();

    expect($array['resolved_pack_ids'])->toBe([SKILL_CTX_UNIVERSAL_PACK_ID])
        ->and($array['prompt_resource_count'])->toBe(1)
        ->and($array['tool_bindings'])->toBe(['guide_search'])
        ->and($array['reference_count'])->toBe(1)
        ->and($array['hook_binding_count'])->toBe(0);
});
