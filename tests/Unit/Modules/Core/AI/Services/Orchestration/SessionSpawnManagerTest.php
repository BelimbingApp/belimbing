<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Orchestration\SpawnEnvelope;
use App\Modules\Core\AI\Enums\OrchestrationSessionStatus;
use App\Modules\Core\AI\Exceptions\SpawnDepthExceededException;
use App\Modules\Core\AI\Exceptions\SpawnPolicyViolationException;
use App\Modules\Core\AI\Jobs\SpawnAgentSessionJob;
use App\Modules\Core\AI\Models\OrchestrationSession;
use App\Modules\Core\AI\Services\Orchestration\OrchestrationPolicyService;
use App\Modules\Core\AI\Services\Orchestration\SessionSpawnManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SPAWN_TASK = 'Resolve the open IT ticket #42';
const SPAWN_TASK_TYPE = 'resolve_ticket';
const SPAWN_CONTEXT_KEY = 'ticket_id';

/**
 * Create agent employees for spawn tests.
 *
 * @return array{parent: Employee, child: Employee, extra: Employee}
 */
function createSpawnAgents(): array
{
    $company = Company::factory()->create();

    return [
        'parent' => Employee::factory()->create([
            'company_id' => $company->id,
            'employee_type' => 'agent',
            'status' => 'active',
        ]),
        'child' => Employee::factory()->create([
            'company_id' => $company->id,
            'employee_type' => 'agent',
            'status' => 'active',
        ]),
        'extra' => Employee::factory()->create([
            'company_id' => $company->id,
            'employee_type' => 'agent',
            'status' => 'active',
        ]),
    ];
}

function makeSpawnManager(?OrchestrationPolicyService $policy = null): SessionSpawnManager
{
    return new SessionSpawnManager(
        $policy ?? new OrchestrationPolicyService,
    );
}

function makeSpawnEnvelope(int $parentId, int $childId, array $overrides = []): SpawnEnvelope
{
    return new SpawnEnvelope(
        parentEmployeeId: $parentId,
        childEmployeeId: $childId,
        task: $overrides['task'] ?? SPAWN_TASK,
        parentSessionId: $overrides['parentSessionId'] ?? null,
        parentRunId: $overrides['parentRunId'] ?? null,
        parentDispatchId: $overrides['parentDispatchId'] ?? null,
        taskType: $overrides['taskType'] ?? SPAWN_TASK_TYPE,
        contextPayload: $overrides['contextPayload'] ?? [],
        modelOverride: $overrides['modelOverride'] ?? null,
        actingForUserId: $overrides['actingForUserId'] ?? null,
    );
}

/**
 * Create an OrchestrationSession directly in the DB for lineage tests.
 */
function createParentOrchestrationSession(int $parentId, int $childId, int $depth = 1): OrchestrationSession
{
    /** @var OrchestrationSession */
    return OrchestrationSession::unguarded(fn () => OrchestrationSession::query()->create([
        'id' => OrchestrationSession::ID_PREFIX.'test_'.Str::random(6),
        'parent_employee_id' => $parentId,
        'child_employee_id' => $childId,
        'task' => SPAWN_TASK,
        'status' => OrchestrationSessionStatus::Running,
        'depth' => $depth,
    ]));
}

// --- spawn ---

it('creates an orchestration session with correct lineage and dispatches job', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $manager = makeSpawnManager();
    $envelope = makeSpawnEnvelope($agents['parent']->id, $agents['child']->id, [
        'parentRunId' => 'run_abc',
        'parentDispatchId' => 'op_xyz',
        'contextPayload' => [SPAWN_CONTEXT_KEY => 42],
    ]);

    $session = $manager->spawn($envelope);

    expect($session)->toBeInstanceOf(OrchestrationSession::class)
        ->and($session->id)->toStartWith(OrchestrationSession::ID_PREFIX)
        ->and($session->parent_employee_id)->toBe($agents['parent']->id)
        ->and($session->child_employee_id)->toBe($agents['child']->id)
        ->and($session->task)->toBe(SPAWN_TASK)
        ->and($session->task_type)->toBe(SPAWN_TASK_TYPE)
        ->and($session->status)->toBe(OrchestrationSessionStatus::Pending)
        ->and($session->depth)->toBe(1)
        ->and($session->parent_run_id)->toBe('run_abc')
        ->and($session->parent_dispatch_id)->toBe('op_xyz')
        ->and($session->spawn_envelope)->toBeArray()
        ->and($session->spawn_envelope['task'])->toBe(SPAWN_TASK)
        ->and($session->spawn_envelope['context_payload'][SPAWN_CONTEXT_KEY] ?? null)->toBe(42);

    // Verify persisted to DB
    $found = OrchestrationSession::query()->find($session->id);
    expect($found)->not->toBeNull()
        ->and($found->task)->toBe(SPAWN_TASK);

    Queue::assertPushed(SpawnAgentSessionJob::class, function (SpawnAgentSessionJob $job) use ($session): bool {
        return $job->orchestrationSessionId === $session->id;
    });
});

it('increments depth when spawning from a parent session', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $parent = createParentOrchestrationSession($agents['parent']->id, $agents['child']->id, depth: 1);

    $manager = makeSpawnManager();
    $envelope = makeSpawnEnvelope($agents['child']->id, $agents['extra']->id, [
        'parentSessionId' => $parent->id,
    ]);

    $session = $manager->spawn($envelope);

    expect($session->depth)->toBe(2);
});

it('sets depth to 1 when parent session reference does not exist', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $manager = makeSpawnManager();
    $envelope = makeSpawnEnvelope($agents['parent']->id, $agents['child']->id, [
        'parentSessionId' => 'orch_nonexistent_123',
    ]);

    $session = $manager->spawn($envelope);

    expect($session->depth)->toBe(1);
});

// --- policy enforcement ---

it('throws SpawnPolicyViolationException for self-spawn', function (): void {
    $agents = createSpawnAgents();
    $manager = makeSpawnManager();
    $envelope = makeSpawnEnvelope($agents['parent']->id, $agents['parent']->id);

    $manager->spawn($envelope);
})->throws(SpawnPolicyViolationException::class);

// --- depth enforcement ---

it('throws SpawnDepthExceededException when depth exceeds limit', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $parent = createParentOrchestrationSession($agents['parent']->id, $agents['child']->id, depth: 3);

    $manager = makeSpawnManager();
    $envelope = makeSpawnEnvelope($agents['child']->id, $agents['extra']->id, [
        'parentSessionId' => $parent->id,
    ]);

    $manager->spawn($envelope);
})->throws(SpawnDepthExceededException::class);

it('allows spawning at the max depth boundary', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $parent = createParentOrchestrationSession($agents['parent']->id, $agents['child']->id, depth: 2);

    $manager = makeSpawnManager();
    $envelope = makeSpawnEnvelope($agents['child']->id, $agents['extra']->id, [
        'parentSessionId' => $parent->id,
    ]);

    $session = $manager->spawn($envelope);

    expect($session->depth)->toBe(3);
});

// --- find ---

it('finds an existing orchestration session by ID', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $manager = makeSpawnManager();
    $session = $manager->spawn(makeSpawnEnvelope($agents['parent']->id, $agents['child']->id));

    $found = $manager->find($session->id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($session->id);
});

it('returns null for a non-existent session ID', function (): void {
    $manager = makeSpawnManager();

    expect($manager->find('orch_nonexistent'))->toBeNull();
});

// --- childrenOf ---

it('returns child sessions spawned by a parent session', function (): void {
    Queue::fake();

    $agents = createSpawnAgents();
    $parent = createParentOrchestrationSession($agents['parent']->id, $agents['child']->id);
    $manager = makeSpawnManager();

    $child1 = $manager->spawn(makeSpawnEnvelope($agents['child']->id, $agents['extra']->id, [
        'parentSessionId' => $parent->id,
    ]));
    $child2 = $manager->spawn(makeSpawnEnvelope($agents['child']->id, $agents['parent']->id, [
        'parentSessionId' => $parent->id,
    ]));

    $children = $manager->childrenOf($parent->id);

    expect($children)->toHaveCount(2)
        ->and($children->pluck('id')->all())->toContain($child1->id, $child2->id);
});

it('returns empty collection when no children exist', function (): void {
    $agents = createSpawnAgents();
    $parent = createParentOrchestrationSession($agents['parent']->id, $agents['child']->id);
    $manager = makeSpawnManager();

    expect($manager->childrenOf($parent->id))->toHaveCount(0);
});
