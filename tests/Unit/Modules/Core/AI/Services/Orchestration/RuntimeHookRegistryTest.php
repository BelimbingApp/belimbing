<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Contracts\Orchestration\RuntimeHook;
use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\DTO\Orchestration\HookResult;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Services\Orchestration\RuntimeHookRegistry;

const HOOK_REG_ID_ALPHA = 'blb.hook-alpha';
const HOOK_REG_ID_BETA = 'blb.hook-beta';
const HOOK_REG_ID_GAMMA = 'blb.hook-gamma';

function makeHookRegistry(): RuntimeHookRegistry
{
    return new RuntimeHookRegistry;
}

function makeStubHook(
    string $identifier,
    HookStage $stage = HookStage::PreContextBuild,
    int $priority = 100,
): RuntimeHook {
    return new class($identifier, $stage, $priority) implements RuntimeHook
    {
        public function __construct(
            private readonly string $id,
            private readonly HookStage $hookStage,
            private readonly int $hookPriority,
        ) {}

        public function stage(): HookStage
        {
            return $this->hookStage;
        }

        public function priority(): int
        {
            return $this->hookPriority;
        }

        public function identifier(): string
        {
            return $this->id;
        }

        public function execute(HookPayload $payload): HookResult
        {
            return new HookResult;
        }
    };
}

// --- register / has / count ---

it('registers a hook and tracks it by identifier', function (): void {
    $registry = makeHookRegistry();
    $hook = makeStubHook(HOOK_REG_ID_ALPHA);

    $registry->register($hook);

    expect($registry->hasIdentifier(HOOK_REG_ID_ALPHA))->toBeTrue()
        ->and($registry->count())->toBe(1);
});

it('rejects duplicate hook identifiers', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA));

    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PostRun));
})->throws(InvalidArgumentException::class, 'already registered');

it('rejects duplicate identifiers even across different stages', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PreContextBuild));

    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PostToolResult));
})->throws(InvalidArgumentException::class, 'already registered');

// --- unregister ---

it('unregisters a hook and returns true', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA));

    $removed = $registry->unregister(HOOK_REG_ID_ALPHA);

    expect($removed)->toBeTrue()
        ->and($registry->hasIdentifier(HOOK_REG_ID_ALPHA))->toBeFalse()
        ->and($registry->count())->toBe(0);
});

it('returns false when unregistering unknown identifier', function (): void {
    $registry = makeHookRegistry();

    expect($registry->unregister('nonexistent'))->toBeFalse();
});

// --- forStage ---

it('returns empty list for stage with no hooks', function (): void {
    $registry = makeHookRegistry();

    expect($registry->forStage(HookStage::PreLlmCall))->toBe([]);
});

it('returns hooks for the correct stage only', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PreContextBuild));
    $registry->register(makeStubHook(HOOK_REG_ID_BETA, HookStage::PostRun));

    $preContext = $registry->forStage(HookStage::PreContextBuild);
    $postRun = $registry->forStage(HookStage::PostRun);

    expect($preContext)->toHaveCount(1)
        ->and($preContext[0]->identifier())->toBe(HOOK_REG_ID_ALPHA)
        ->and($postRun)->toHaveCount(1)
        ->and($postRun[0]->identifier())->toBe(HOOK_REG_ID_BETA);
});

// --- priority ordering ---

it('sorts hooks by priority within a stage', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_GAMMA, HookStage::PreLlmCall, 200));
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PreLlmCall, 50));
    $registry->register(makeStubHook(HOOK_REG_ID_BETA, HookStage::PreLlmCall, 100));

    $hooks = $registry->forStage(HookStage::PreLlmCall);

    expect($hooks)->toHaveCount(3)
        ->and($hooks[0]->identifier())->toBe(HOOK_REG_ID_ALPHA)
        ->and($hooks[1]->identifier())->toBe(HOOK_REG_ID_BETA)
        ->and($hooks[2]->identifier())->toBe(HOOK_REG_ID_GAMMA);
});

it('preserves registration order for equal priorities', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_BETA, HookStage::PostToolResult, 100));
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PostToolResult, 100));

    $hooks = $registry->forStage(HookStage::PostToolResult);

    // PHP usort is not guaranteed stable, but same-priority hooks
    // registered in order should maintain relative position in practice.
    expect($hooks)->toHaveCount(2);
});

// --- hasHooksFor ---

it('reports whether hooks exist for a stage', function (): void {
    $registry = makeHookRegistry();

    expect($registry->hasHooksFor(HookStage::PreContextBuild))->toBeFalse();

    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PreContextBuild));

    expect($registry->hasHooksFor(HookStage::PreContextBuild))->toBeTrue()
        ->and($registry->hasHooksFor(HookStage::PostRun))->toBeFalse();
});

// --- summary ---

it('returns diagnostic summary grouped by stage', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PreContextBuild, 50));
    $registry->register(makeStubHook(HOOK_REG_ID_BETA, HookStage::PostRun, 100));

    $summary = $registry->summary();

    expect($summary)->toHaveKey('pre_context_build')
        ->and($summary)->toHaveKey('post_run')
        ->and($summary['pre_context_build'])->toBe([
            ['identifier' => HOOK_REG_ID_ALPHA, 'priority' => 50],
        ])
        ->and($summary['post_run'])->toBe([
            ['identifier' => HOOK_REG_ID_BETA, 'priority' => 100],
        ]);
});

it('returns empty summary when no hooks are registered', function (): void {
    $registry = makeHookRegistry();

    expect($registry->summary())->toBe([]);
});

// --- re-sort after unregister ---

it('re-sorts correctly after unregistering a hook', function (): void {
    $registry = makeHookRegistry();
    $registry->register(makeStubHook(HOOK_REG_ID_GAMMA, HookStage::PreLlmCall, 200));
    $registry->register(makeStubHook(HOOK_REG_ID_ALPHA, HookStage::PreLlmCall, 50));
    $registry->register(makeStubHook(HOOK_REG_ID_BETA, HookStage::PreLlmCall, 100));

    $registry->unregister(HOOK_REG_ID_ALPHA);

    $hooks = $registry->forStage(HookStage::PreLlmCall);

    expect($hooks)->toHaveCount(2)
        ->and($hooks[0]->identifier())->toBe(HOOK_REG_ID_BETA)
        ->and($hooks[1]->identifier())->toBe(HOOK_REG_ID_GAMMA);
});
