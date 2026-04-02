<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Contracts\Orchestration\RuntimeHook;
use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\DTO\Orchestration\HookResult;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Services\Orchestration\RuntimeHookRegistry;
use App\Modules\Core\AI\Services\Orchestration\RuntimeHookRunner;
use Psr\Log\NullLogger;

const HOOK_RUN_ID_PREFIX = 'run_test_';
const HOOK_RUN_EMPLOYEE_ID = 42;
const HOOK_RUN_PROMPT_SECTION = 'Additional context from hook.';
const HOOK_RUN_TOOL_TO_ADD = 'extra_tool';
const HOOK_RUN_TOOL_TO_REMOVE = 'banned_tool';
const HOOK_RUN_AUG_KEY = 'custom_flag';
const HOOK_RUN_AUG_VALUE = true;

function makeHookRunner(): RuntimeHookRunner
{
    return new RuntimeHookRunner(new RuntimeHookRegistry, new NullLogger);
}

function makeHookRunnerWithRegistry(RuntimeHookRegistry $registry): RuntimeHookRunner
{
    return new RuntimeHookRunner($registry, new NullLogger);
}

function makeRunnerPayload(HookStage $stage = HookStage::PreContextBuild): HookPayload
{
    return new HookPayload(
        stage: $stage,
        runId: HOOK_RUN_ID_PREFIX.'abc',
        employeeId: HOOK_RUN_EMPLOYEE_ID,
    );
}

/**
 * Build a RuntimeHook anonymous class with configurable behavior.
 *
 * @param  array{
 *     promptSections?: list<string>,
 *     toolsToAdd?: list<string>,
 *     toolsToRemove?: list<string>,
 *     augmentations?: array<string, mixed>,
 *     metadata?: array<string, mixed>,
 *     handled?: bool,
 * }  $resultData
 */
function makeRunnerHook(
    string $identifier,
    HookStage $stage = HookStage::PreContextBuild,
    int $priority = 100,
    array $resultData = [],
): RuntimeHook {
    return new class($identifier, $stage, $priority, $resultData) implements RuntimeHook
    {
        public function __construct(
            private readonly string $id,
            private readonly HookStage $hookStage,
            private readonly int $hookPriority,
            private readonly array $resultData,
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
            return new HookResult(
                handled: $this->resultData['handled'] ?? true,
                augmentations: $this->resultData['augmentations'] ?? [],
                promptSections: $this->resultData['promptSections'] ?? [],
                toolsToAdd: $this->resultData['toolsToAdd'] ?? [],
                toolsToRemove: $this->resultData['toolsToRemove'] ?? [],
                metadata: $this->resultData['metadata'] ?? [],
            );
        }
    };
}

function makeFailingHook(string $identifier, HookStage $stage = HookStage::PreContextBuild): RuntimeHook
{
    return new class($identifier, $stage) implements RuntimeHook
    {
        public function __construct(
            private readonly string $id,
            private readonly HookStage $hookStage,
        ) {}

        public function stage(): HookStage
        {
            return $this->hookStage;
        }

        public function priority(): int
        {
            return 100;
        }

        public function identifier(): string
        {
            return $this->id;
        }

        public function execute(HookPayload $payload): HookResult
        {
            throw new RuntimeException('Hook intentionally failed');
        }
    };
}

// --- empty stage ---

it('returns empty result when no hooks are registered', function (): void {
    $runner = makeHookRunner();
    $payload = makeRunnerPayload();

    $result = $runner->run(HookStage::PreContextBuild, $payload);

    expect($result->hasChanges())->toBeFalse()
        ->and($result->hasExecutions())->toBeFalse()
        ->and($result->executedCount)->toBe(0)
        ->and($result->failedCount)->toBe(0);
});

// --- single hook execution ---

it('executes a single hook and returns its augmentations', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-single', resultData: [
        'promptSections' => [HOOK_RUN_PROMPT_SECTION],
        'toolsToAdd' => [HOOK_RUN_TOOL_TO_ADD],
        'toolsToRemove' => [HOOK_RUN_TOOL_TO_REMOVE],
        'augmentations' => [HOOK_RUN_AUG_KEY => HOOK_RUN_AUG_VALUE],
        'metadata' => ['source' => 'test'],
    ]));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    expect($result->promptSections)->toBe([HOOK_RUN_PROMPT_SECTION])
        ->and($result->toolsToAdd)->toBe([HOOK_RUN_TOOL_TO_ADD])
        ->and($result->toolsToRemove)->toBe([HOOK_RUN_TOOL_TO_REMOVE])
        ->and($result->augmentations)->toBe([HOOK_RUN_AUG_KEY => HOOK_RUN_AUG_VALUE])
        ->and($result->executedCount)->toBe(1)
        ->and($result->failedCount)->toBe(0)
        ->and($result->hasChanges())->toBeTrue()
        ->and($result->hookMetadata['blb.runner-single']['status'])->toBe('executed');
});

// --- merge semantics ---

it('merges prompt sections from multiple hooks in priority order', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-p200', priority: 200, resultData: [
        'promptSections' => ['Section from late hook.'],
    ]));
    $registry->register(makeRunnerHook('blb.runner-p50', priority: 50, resultData: [
        'promptSections' => ['Section from early hook.'],
    ]));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    expect($result->promptSections)->toBe([
        'Section from early hook.',
        'Section from late hook.',
    ]);
});

it('deduplicates tool additions across hooks', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-tools-a', resultData: [
        'toolsToAdd' => ['tool_x', 'tool_y'],
    ]));
    $registry->register(makeRunnerHook('blb.runner-tools-b', resultData: [
        'toolsToAdd' => ['tool_y', 'tool_z'],
    ]));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    $tools = $result->toolsToAdd;
    sort($tools);

    expect($tools)->toBe(['tool_x', 'tool_y', 'tool_z']);
});

it('later hook augmentations overwrite earlier ones', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-aug-first', priority: 50, resultData: [
        'augmentations' => ['key' => 'first', 'shared' => 'from_first'],
    ]));
    $registry->register(makeRunnerHook('blb.runner-aug-second', priority: 100, resultData: [
        'augmentations' => ['key' => 'second', 'extra' => 'only_second'],
    ]));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    expect($result->augmentations)->toBe([
        'key' => 'second',
        'shared' => 'from_first',
        'extra' => 'only_second',
    ]);
});

// --- noop (handled=false) ---

it('records skipped status for hooks that return handled=false', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-noop', resultData: [
        'handled' => false,
    ]));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    expect($result->hasChanges())->toBeFalse()
        ->and($result->executedCount)->toBe(1)
        ->and($result->hookMetadata['blb.runner-noop']['status'])->toBe('skipped');
});

// --- failure isolation ---

it('isolates hook failures without aborting the run', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-good', priority: 50, resultData: [
        'promptSections' => ['Good hook output.'],
    ]));
    $registry->register(makeFailingHook('blb.runner-bad', HookStage::PreContextBuild));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    expect($result->promptSections)->toBe(['Good hook output.'])
        ->and($result->executedCount)->toBe(1)
        ->and($result->failedCount)->toBe(1)
        ->and($result->hookMetadata['blb.runner-bad']['status'])->toBe('failed')
        ->and($result->hookMetadata['blb.runner-good']['status'])->toBe('executed');
});

// --- hasHooksFor delegation ---

it('delegates hasHooksFor to the registry', function (): void {
    $registry = new RuntimeHookRegistry;
    $runner = makeHookRunnerWithRegistry($registry);

    expect($runner->hasHooksFor(HookStage::PostRun))->toBeFalse();

    $registry->register(makeRunnerHook('blb.runner-post', stage: HookStage::PostRun));

    expect($runner->hasHooksFor(HookStage::PostRun))->toBeTrue();
});

// --- toArray serialization ---

it('serializes merged result to array for run metadata', function (): void {
    $registry = new RuntimeHookRegistry;
    $registry->register(makeRunnerHook('blb.runner-serial', resultData: [
        'promptSections' => ['Section A.'],
        'toolsToAdd' => ['tool_a'],
        'augmentations' => ['flag' => true],
    ]));

    $runner = makeHookRunnerWithRegistry($registry);
    $result = $runner->run(HookStage::PreContextBuild, makeRunnerPayload());

    $array = $result->toArray();

    expect($array['prompt_section_count'])->toBe(1)
        ->and($array['tools_to_add'])->toBe(['tool_a'])
        ->and($array['augmentation_count'])->toBe(1)
        ->and($array['executed_count'])->toBe(1)
        ->and($array['failed_count'])->toBe(0)
        ->and($array['hooks'])->toHaveKey('blb.runner-serial');
});
