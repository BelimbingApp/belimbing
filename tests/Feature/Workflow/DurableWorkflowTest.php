<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Contracts\ContextualTransitionGuard;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Models\ProcessEvent;
use App\Base\Workflow\Models\ProcessRun;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\TransitionOutboxMessage;
use App\Base\Workflow\Process\Definitions\ProcessDefinition;
use App\Base\Workflow\Process\Definitions\ProcessDependency;
use App\Base\Workflow\Process\Definitions\ProcessStep;
use App\Base\Workflow\Process\Enums\DependencyMode;
use App\Base\Workflow\Process\Enums\ProcessRunStatus;
use App\Base\Workflow\Process\Enums\ProcessWorkStatus;
use App\Base\Workflow\Process\ProcessCoordinationException;
use App\Base\Workflow\Process\ProcessCoordinator;
use App\Base\Workflow\Process\ProcessDefinitionRegistry;
use App\Base\Workflow\Services\TransitionOutboxDispatcher;
use App\Base\Workflow\Services\WorkflowEngine;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

it('serializes competing transitions against the persisted status', function (): void {
    Event::fake([TransitionCompleted::class]);

    $user = createAdminUser();
    $company = Company::factory()->create(['status' => 'active']);

    foreach (['paused', 'archived'] as $to) {
        StatusTransition::query()->create([
            'flow' => 'coverage',
            'from_code' => 'active',
            'to_code' => $to,
            'is_active' => true,
        ]);
    }

    $firstWorker = Company::query()->findOrFail($company->id);
    $secondWorker = Company::query()->findOrFail($company->id);
    $context = new TransitionContext(Actor::forUser($user));

    $first = app(WorkflowEngine::class)->transition($firstWorker, 'coverage', 'paused', $context);
    $second = app(WorkflowEngine::class)->transition($secondWorker, 'coverage', 'archived', $context);

    expect($first->success)->toBeTrue()
        ->and($second->success)->toBeFalse()
        ->and($second->reason)->toContain("from 'paused'")
        ->and($company->refresh()->status)->toBe('paused')
        ->and(StatusHistory::query()->where('flow', 'coverage')->where('flow_id', $company->id)->count())->toBe(1)
        ->and(TransitionOutboxMessage::query()->count())->toBe(1);
});

it('passes transition input to contextual guards and records the actor namespace', function (): void {
    Event::fake([TransitionCompleted::class]);

    $user = createAdminUser();
    $company = Company::factory()->create(['status' => 'active']);
    StatusTransition::query()->create([
        'flow' => 'contextual-coverage',
        'from_code' => 'active',
        'to_code' => 'paused',
        'guard_class' => ContextAwareCoverageGuard::class,
        'is_active' => true,
    ]);

    $result = app(WorkflowEngine::class)->transition(
        $company,
        'contextual-coverage',
        'paused',
        new TransitionContext(
            actor: Actor::forUser($user),
            metadata: ['allow_transition' => true],
        ),
    );

    expect($result->success)->toBeTrue()
        ->and($company->refresh()->status)->toBe('paused')
        ->and(StatusHistory::query()->sole()->actor_type)->toBe('user');
});

it('recovers a failed transition event delivery without dispatching a delivered event twice', function (): void {
    Event::forget(TransitionCompleted::class);

    $deliveries = 0;
    Event::listen(TransitionCompleted::class, function () use (&$deliveries): void {
        $deliveries++;
        throw new RuntimeException('Temporary listener outage.');
    });

    $user = createAdminUser();
    $company = Company::factory()->create(['status' => 'active']);
    $transition = StatusTransition::query()->create([
        'flow' => 'coverage',
        'from_code' => 'active',
        'to_code' => 'paused',
        'is_active' => true,
    ]);

    $result = app(WorkflowEngine::class)->transition(
        $company,
        'coverage',
        'paused',
        new TransitionContext(Actor::forUser($user)),
    );
    $message = TransitionOutboxMessage::query()->sole();

    expect($result->success)->toBeTrue()
        ->and($company->refresh()->status)->toBe('paused')
        ->and($message->delivered_at)->toBeNull()
        ->and($message->attempts)->toBe(1)
        ->and($deliveries)->toBe(1);

    // A delayed transition event must retain the state that committed with it,
    // even if the aggregate has advanced before delivery recovers.
    $company->forceFill(['status' => 'archived'])->save();
    $transition->forceFill([
        'from_code' => 'archived',
        'to_code' => 'active',
        'label' => 'Changed after commit',
    ])->save();

    Event::forget(TransitionCompleted::class);
    Event::listen(TransitionCompleted::class, function (TransitionCompleted $event) use (&$deliveries, $company): void {
        expect($event->payload->flowId)->toBe($company->id)
            ->and($event->payload->fromStatus)->toBe('active')
            ->and($event->payload->toStatus)->toBe('paused')
            ->and($event->transition->from_code)->toBe('active')
            ->and($event->transition->to_code)->toBe('paused')
            ->and($event->transition->label)->toBeNull()
            ->and($event->model->getAttribute('status'))->toBe('paused')
            ->and($company->refresh()->status)->toBe('archived');
        $deliveries++;
    });

    $this->travel(3)->seconds();
    $dispatcher = app(TransitionOutboxDispatcher::class);

    expect($dispatcher->deliverDue())->toBe(1)
        ->and($message->refresh()->delivered_at)->not->toBeNull()
        ->and($message->attempts)->toBe(2)
        ->and($deliveries)->toBe(2)
        ->and($dispatcher->deliverDue())->toBe(0)
        ->and($deliveries)->toBe(2);
});

it('starts and signals a process idempotently', function (): void {
    $definition = new ProcessDefinition('test.signal', 1, [
        new ProcessStep('wait', 'Wait for filing', requiredSignal: 'filing.received'),
    ]);
    app(ProcessDefinitionRegistry::class)->register($definition);
    $coordinator = app(ProcessCoordinator::class);

    $first = $coordinator->start('test.signal', ['filing' => 42], 'filing-42');
    $second = $coordinator->start('test.signal', ['filing' => 999], 'filing-42');

    expect($second->id)->toBe($first->id)
        ->and(ProcessRun::query()->where('definition_key', 'test.signal')->count())->toBe(1)
        ->and($first->workItems()->sole()->status)->toBe(ProcessWorkStatus::PENDING);

    $coordinator->signal($first, 'filing.received', ['source' => 'registry']);
    $coordinator->signal($first, 'filing.received', ['source' => 'duplicate']);

    expect($first->workItems()->sole()->refresh()->status)->toBe(ProcessWorkStatus::AVAILABLE)
        ->and($first->workItems()->sole()->signal_payload)->toBe(['source' => 'registry'])
        ->and(ProcessEvent::query()->where('process_run_id', $first->id)->where('type', 'signal.received')->count())->toBe(1);

    $claim = $coordinator->claim('signal-worker');
    $coordinator->complete($claim);
    $coordinator->signal($first, 'late.fact', ['ignored' => true]);

    expect($first->refresh()->status)->toBe(ProcessRunStatus::COMPLETED)
        ->and($first->events()->where('type', 'process.completed')->count())->toBe(1)
        ->and($first->events()->where('type', 'signal.ignored')->count())->toBe(1);
});

it('reports only process runs that reconciliation actually inspected', function (): void {
    $coordinator = app(ProcessCoordinator::class);

    expect($coordinator->reconcile(PHP_INT_MAX))->toBe(0);
});

it('coordinates fan out and all or any fan in with explicit acceptable outcomes', function (): void {
    $definition = new ProcessDefinition('test.fan-in', 1, [
        new ProcessStep('source', 'Source'),
        new ProcessStep('left', 'Left branch', [new ProcessDependency('source')]),
        new ProcessStep('right', 'Right branch', [new ProcessDependency('source')]),
        new ProcessStep('all_join', 'All join', [
            new ProcessDependency('left', ['approved']),
            new ProcessDependency('right', ['completed']),
        ]),
        new ProcessStep('any_join', 'Any join', [
            new ProcessDependency('left', ['approved']),
            new ProcessDependency('right', ['completed']),
        ], dependencyMode: DependencyMode::ANY),
    ]);
    app(ProcessDefinitionRegistry::class)->register($definition);
    $coordinator = app(ProcessCoordinator::class);
    $run = $coordinator->start('test.fan-in', idempotencyKey: 'one');

    $source = $coordinator->claim('worker');
    expect($source?->workItem->step_key)->toBe('source');
    $coordinator->complete($source);

    $left = $coordinator->claim('worker');
    expect($left?->workItem->step_key)->toBe('left');
    $coordinator->complete($left, outcome: 'approved');

    expect($run->workItems()->where('step_key', 'all_join')->sole()->status)->toBe(ProcessWorkStatus::PENDING)
        ->and($run->workItems()->where('step_key', 'any_join')->sole()->status)->toBe(ProcessWorkStatus::AVAILABLE);

    $right = $coordinator->claim('worker');
    expect($right?->workItem->step_key)->toBe('right');
    $coordinator->complete($right);

    expect($run->workItems()->where('step_key', 'all_join')->sole()->status)->toBe(ProcessWorkStatus::AVAILABLE);

    $firstJoin = $coordinator->claim('worker');
    $coordinator->complete($firstJoin);
    $secondJoin = $coordinator->claim('worker');
    $coordinator->complete($secondJoin);

    expect([$firstJoin?->workItem->step_key, $secondJoin?->workItem->step_key])
        ->toEqualCanonicalizing(['all_join', 'any_join'])
        ->and($run->refresh()->status)->toBe(ProcessRunStatus::COMPLETED)
        ->and($run->events()->pluck('sequence')->all())->toBe(range(1, $run->events()->count()));
});

it('uses heartbeats to extend a lease and reconciliation to repair an expired lease', function (): void {
    app(ProcessDefinitionRegistry::class)->register(new ProcessDefinition('test.lease', 1, [
        new ProcessStep('work', 'Recoverable work', maxAttempts: 2),
    ]));
    $coordinator = app(ProcessCoordinator::class);
    $run = $coordinator->start('test.lease');
    $firstClaim = $coordinator->claim('worker-a', leaseSeconds: 5);

    $this->travel(4)->seconds();
    $coordinator->heartbeat($firstClaim, leaseSeconds: 5);
    $this->travel(2)->seconds();
    $coordinator->reconcile($run);

    expect($firstClaim->workItem->refresh()->status)->toBe(ProcessWorkStatus::LEASED);

    $this->travel(4)->seconds();
    $coordinator->reconcile($run);

    expect($firstClaim->workItem->refresh()->status)->toBe(ProcessWorkStatus::AVAILABLE)
        ->and($firstClaim->workItem->lease_token)->toBeNull();

    expect(fn () => $coordinator->complete($firstClaim))->toThrow(ProcessCoordinationException::class);

    $secondClaim = $coordinator->claim('worker-b', leaseSeconds: 5);
    expect($secondClaim?->leaseToken)->not->toBe($firstClaim?->leaseToken)
        ->and($secondClaim?->workItem->attempts)->toBe(2);

    $coordinator->complete($secondClaim);

    expect($run->refresh()->status)->toBe(ProcessRunStatus::COMPLETED)
        ->and($run->workItems()->sole()->outcome)->toBe('completed');
});

it('blocks downstream work when a final failure makes its dependency impossible', function (): void {
    app(ProcessDefinitionRegistry::class)->register(new ProcessDefinition('test.failure', 1, [
        new ProcessStep('attempt', 'Attempt', maxAttempts: 1),
        new ProcessStep('dependent', 'Dependent', [new ProcessDependency('attempt')]),
    ]));
    $coordinator = app(ProcessCoordinator::class);
    $run = $coordinator->start('test.failure');
    $claim = $coordinator->claim('worker');

    $coordinator->fail($claim, 'Evidence source is unavailable.');

    expect($run->workItems()->where('step_key', 'attempt')->sole()->status)->toBe(ProcessWorkStatus::FAILED)
        ->and($run->workItems()->where('step_key', 'dependent')->sole()->status)->toBe(ProcessWorkStatus::BLOCKED)
        ->and($run->refresh()->status)->toBe(ProcessRunStatus::FAILED);
});

it('pauses future claims without discarding completed facts', function (): void {
    app(ProcessDefinitionRegistry::class)->register(new ProcessDefinition('test.pause', 1, [
        new ProcessStep('gather', 'Gather'),
        new ProcessStep('review', 'Review', [new ProcessDependency('gather')]),
    ]));
    $coordinator = app(ProcessCoordinator::class);
    $run = $coordinator->start('test.pause');
    $gather = $coordinator->claim('worker');
    $coordinator->complete($gather, ['fact_id' => 77], resultRef: 'fact:77');

    $coordinator->pause($run, 'Owner paused coverage.');

    expect($run->refresh()->status)->toBe(ProcessRunStatus::PAUSED)
        ->and($run->workItems()->where('step_key', 'gather')->sole()->output)->toBe(['fact_id' => 77])
        ->and($run->workItems()->where('step_key', 'gather')->sole()->result_ref)->toBe('fact:77')
        ->and($run->workItems()->where('step_key', 'review')->sole()->status)->toBe(ProcessWorkStatus::AVAILABLE)
        ->and($coordinator->claim('another-worker'))->toBeNull();

    $coordinator->resume($run);
    $review = $coordinator->claim('another-worker');

    expect($review?->workItem->step_key)->toBe('review')
        ->and($run->events()->where('type', 'process.paused')->count())->toBe(1)
        ->and($run->events()->where('type', 'process.resumed')->count())->toBe(1);
});

it('reconciliation idempotently restores missing code-defined work topology', function (): void {
    $company = Company::factory()->create();
    app(ProcessDefinitionRegistry::class)->register(new ProcessDefinition('test.materialize', 1, [
        new ProcessStep('source', 'Source', executorKey: 'source.reader'),
        new ProcessStep(
            'analysis',
            'Analysis',
            [new ProcessDependency('source')],
            executorKey: 'analysis.agent',
            metadata: ['role' => 'critic'],
            priority: 20,
            inputRef: 'company:'.$company->id,
        ),
    ]));
    $coordinator = app(ProcessCoordinator::class);
    $run = $coordinator->start(
        'test.materialize',
        subjectType: Company::class,
        subjectId: $company->id,
        correlationKey: 'company:'.$company->id.':cycle:1',
    );
    $analysis = $run->workItems()->where('step_key', 'analysis')->sole();
    $analysis->delete();

    $coordinator->reconcile($run);
    $restored = $run->workItems()->where('step_key', 'analysis')->sole();
    $coordinator->reconcile($run);

    expect($restored->executor_key)->toBe('analysis.agent')
        ->and($restored->metadata)->toBe(['role' => 'critic'])
        ->and($restored->priority)->toBe(20)
        ->and($restored->input_ref)->toBe('company:'.$company->id)
        ->and($restored->dependencies()->count())->toBe(1)
        ->and($run->workItems()->where('step_key', 'analysis')->count())->toBe(1)
        ->and($run->correlation_key)->toBe('company:'.$company->id.':cycle:1')
        ->and($run->subject->is($company))->toBeTrue();
});

it('claims only supported executors and orders work by dynamic process priority', function (): void {
    app(ProcessDefinitionRegistry::class)->register(new ProcessDefinition('test.routing', 1, [
        new ProcessStep('research', 'Research', executorKey: 'investment.research'),
    ]));
    $coordinator = app(ProcessCoordinator::class);
    $low = $coordinator->start('test.routing', priority: 10);
    $high = $coordinator->start('test.routing', priority: 90);

    expect($coordinator->claim('valuation-worker', executorKeys: ['investment.valuation']))->toBeNull();

    $claim = $coordinator->claim('research-worker', executorKeys: ['investment.research']);

    expect($claim?->workItem->process_run_id)->toBe($high->id)
        ->and($claim?->workItem->executor_key)->toBe('investment.research')
        ->and($low->workItems()->sole()->status)->toBe(ProcessWorkStatus::AVAILABLE);
});

it('limits claims and reconciliation to an explicitly assigned set of process runs', function (): void {
    app(ProcessDefinitionRegistry::class)->register(new ProcessDefinition('test.scoped-claim', 1, [
        new ProcessStep('research', 'Research', executorKey: 'investment.research'),
    ]));
    $coordinator = app(ProcessCoordinator::class);
    $assigned = $coordinator->start('test.scoped-claim', priority: 10);
    $unassigned = $coordinator->start('test.scoped-claim', priority: 90);

    expect($coordinator->claim('research-worker', processRunIds: []))->toBeNull();

    $claim = $coordinator->claim(
        'research-worker',
        executorKeys: ['investment.research'],
        processRunIds: [$assigned->id],
    );

    expect($claim?->workItem->process_run_id)->toBe($assigned->id)
        ->and($unassigned->workItems()->sole()->status)->toBe(ProcessWorkStatus::AVAILABLE)
        ->and(fn () => $coordinator->claim('research-worker', processRunIds: [0]))
        ->toThrow(ProcessCoordinationException::class, 'positive integers');
});

it('rejects changing a process definition without publishing a new version', function (): void {
    $originalRegistry = new ProcessDefinitionRegistry;
    $originalRegistry->register(new ProcessDefinition('test.immutable', 1, [
        new ProcessStep('research', 'Research', executorKey: 'research.v1'),
    ]));
    $original = new ProcessCoordinator($originalRegistry);
    $run = $original->start('test.immutable');

    $changedRegistry = new ProcessDefinitionRegistry;
    $changedRegistry->register(new ProcessDefinition('test.immutable', 1, [
        new ProcessStep('research', 'Research', executorKey: 'research.changed-in-place'),
    ]));
    $changed = new ProcessCoordinator($changedRegistry);

    expect(fn () => $changed->start('test.immutable'))
        ->toThrow(ProcessCoordinationException::class, 'publish a new version')
        ->and($run->refresh()->definition_fingerprint)->toHaveLength(64);
});

final class ContextAwareCoverageGuard implements ContextualTransitionGuard
{
    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult
    {
        return GuardResult::deny('Transition context is required.');
    }

    public function evaluateWithContext(
        Model $model,
        StatusTransition $transition,
        Actor $actor,
        TransitionContext $context,
    ): GuardResult {
        return ($context->metadata['allow_transition'] ?? false) === true
            ? GuardResult::allow()
            : GuardResult::deny('Context denied the transition.');
    }
}
