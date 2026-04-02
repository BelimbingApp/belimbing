<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Services\KnowledgeNavigator;
use App\Modules\Core\AI\Enums\SkillPackStatus;
use App\Modules\Core\AI\Services\Orchestration\SkillPacks\KnowledgeSkillPack;

const KNOWLEDGE_PACK_ID = 'blb.framework-knowledge';
const KNOWLEDGE_PACK_TOOL_BINDING = 'guide_search';

function makeKnowledgeSkillPack(): KnowledgeSkillPack
{
    return new KnowledgeSkillPack(new KnowledgeNavigator);
}

// --- manifest structure ---

it('builds a valid manifest with correct identity', function (): void {
    $pack = makeKnowledgeSkillPack();
    $manifest = $pack->manifest();

    expect($manifest->id)->toBe(KNOWLEDGE_PACK_ID)
        ->and($manifest->version)->toBe('1.0.0')
        ->and($manifest->name)->toBe('BLB Framework Knowledge')
        ->and($manifest->status)->toBe(SkillPackStatus::Ready)
        ->and($manifest->owner)->toBe('Core AI');
});

it('applies to all agents with empty applicableAgentIds', function (): void {
    $manifest = makeKnowledgeSkillPack()->manifest();

    expect($manifest->applicableAgentIds)->toBeEmpty()
        ->and($manifest->appliesTo(1))->toBeTrue()
        ->and($manifest->appliesTo(999))->toBeTrue();
});

// --- prompt resources ---

it('includes framework reference grounding prompt', function (): void {
    $manifest = makeKnowledgeSkillPack()->manifest();

    expect($manifest->promptResources)->not->toBeEmpty();

    $prompt = $manifest->promptResources[0];

    expect($prompt->label)->toBe('framework-knowledge-grounding')
        ->and($prompt->content)->toContain('BLB Framework References')
        ->and($prompt->content)->toContain(KNOWLEDGE_PACK_TOOL_BINDING)
        ->and($prompt->order)->toBe(200);
});

// --- tool bindings ---

it('binds the guide_search tool', function (): void {
    $manifest = makeKnowledgeSkillPack()->manifest();

    expect($manifest->toolBindings)->toBe([KNOWLEDGE_PACK_TOOL_BINDING]);
});

// --- references ---

it('includes all KnowledgeNavigator catalog entries as references', function (): void {
    $navigator = new KnowledgeNavigator;
    $catalogCount = count($navigator->catalog());

    $manifest = makeKnowledgeSkillPack()->manifest();

    expect($manifest->references)->toHaveCount($catalogCount);

    $firstRef = $manifest->references[0];

    expect($firstRef->title)->not->toBeEmpty()
        ->and($firstRef->path)->not->toBeEmpty()
        ->and($firstRef->summary)->not->toBeEmpty();
});

// --- readiness ---

it('declares a readiness check for catalog availability', function (): void {
    $manifest = makeKnowledgeSkillPack()->manifest();

    expect($manifest->readinessChecks)->not->toBeEmpty()
        ->and($manifest->readinessChecks[0])->toContain('KnowledgeNavigator');
});

it('is available and serializable', function (): void {
    $manifest = makeKnowledgeSkillPack()->manifest();

    expect($manifest->isAvailable())->toBeTrue();

    $array = $manifest->toArray();

    expect($array)->toBeArray()
        ->and($array['id'])->toBe(KNOWLEDGE_PACK_ID)
        ->and($array['prompt_resources'])->not->toBeEmpty()
        ->and($array['references'])->not->toBeEmpty();
});
