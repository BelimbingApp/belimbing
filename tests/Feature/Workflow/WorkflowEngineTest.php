<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Base\Workflow\Services\StatusManager;
use App\Base\Workflow\Services\TransitionManager;
use App\Base\Workflow\Services\TransitionValidator;
use App\Base\Workflow\Services\WorkflowEngine;
use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Event;

const WF_TEST_FLOW = 'test_ticket';
const WF_IT_TICKET_FLOW = 'it_ticket';
const WF_STATUS_IN_PROGRESS = 'In Progress';
const WF_TRANSITION_START_WORK = 'Start Work';

/**
 * Seed a test workflow graph for unit-level engine tests.
 *
 * Also seeds the it_ticket flow (same graph) so integration tests
 * using the real Ticket model work without the full seeder.
 */
function seedTestWorkflow(): void
{
    $flows = [WF_TEST_FLOW, WF_IT_TICKET_FLOW];

    foreach ($flows as $flow) {
        Workflow::query()->create([
            'code' => $flow,
            'label' => $flow === WF_TEST_FLOW ? 'Test Ticket' : 'IT Ticket',
            'module' => 'test',
        ]);

        $statuses = [
            ['flow' => $flow, 'code' => 'open', 'label' => 'Open', 'position' => 0],
            ['flow' => $flow, 'code' => 'in_progress', 'label' => WF_STATUS_IN_PROGRESS, 'position' => 1],
            ['flow' => $flow, 'code' => 'resolved', 'label' => 'Resolved', 'position' => 2],
            ['flow' => $flow, 'code' => 'closed', 'label' => 'Closed', 'position' => 3],
        ];

        foreach ($statuses as $status) {
            StatusConfig::query()->create($status);
        }

        $transitions = [
            ['flow' => $flow, 'from_code' => 'open', 'to_code' => 'in_progress', 'label' => WF_TRANSITION_START_WORK],
            ['flow' => $flow, 'from_code' => 'in_progress', 'to_code' => 'resolved', 'label' => 'Resolve'],
            ['flow' => $flow, 'from_code' => 'resolved', 'to_code' => 'closed', 'label' => 'Close'],
            ['flow' => $flow, 'from_code' => 'resolved', 'to_code' => 'open', 'label' => 'Reopen', 'position' => 1],
        ];

        foreach ($transitions as $transition) {
            StatusTransition::query()->create($transition);
        }
    }
}

function createTestActor(): Actor
{
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    return new Actor(
        type: PrincipalType::HUMAN_USER,
        id: $user->id,
        companyId: $company->id,
        attributes: ['role' => 'technician', 'department' => 'IT', 'employee_id' => $employee->id],
    );
}

/**
 * Create a Ticket model instance backed by the real it_tickets table.
 */
function createTestTicket(?Actor $actor = null): Ticket
{
    $actor ??= createTestActor();
    $employeeId = $actor->attributes['employee_id'] ?? null;

    if (! is_int($employeeId)) {
        $employeeId = is_numeric($employeeId) ? (int) $employeeId : null;
    }

    if ($employeeId === null) {
        $employeeId = Employee::factory()->create(['company_id' => $actor->companyId])->id;
    }

    return Ticket::query()->create([
        'company_id' => $actor->companyId,
        'reporter_id' => $employeeId,
        'title' => 'Test printer not working',
        'status' => 'open',
        'priority' => 'medium',
        'category' => 'hardware',
    ]);
}

beforeEach(function (): void {
    seedTestWorkflow();
});

// -- StatusManager Tests --

test('status manager loads all active statuses for a flow', function (): void {
    $manager = app(StatusManager::class);
    $statuses = $manager->getStatuses(WF_TEST_FLOW);

    expect($statuses)->toHaveCount(4);
    expect($statuses->pluck('code')->all())->toBe(['open', 'in_progress', 'resolved', 'closed']);
});

test('status manager returns a specific status by code', function (): void {
    $manager = app(StatusManager::class);
    $status = $manager->getStatus(WF_TEST_FLOW, 'in_progress');

    expect($status)->not->toBeNull();
    expect($status->label)->toBe(WF_STATUS_IN_PROGRESS);
});

test('status manager returns null for non-existent status', function (): void {
    $manager = app(StatusManager::class);
    expect($manager->getStatus(WF_TEST_FLOW, 'nonexistent'))->toBeNull();
});

// -- StatusConfig Model Tests --

test('status config computes next statuses from transitions table', function (): void {
    $status = StatusConfig::query()->where('flow', WF_TEST_FLOW)->where('code', 'resolved')->first();

    $nextStatuses = $status->nextStatuses;

    expect($nextStatuses->all())->toBe(['closed', 'open']);
});

test('terminal status has no next statuses', function (): void {
    $status = StatusConfig::query()->where('flow', WF_TEST_FLOW)->where('code', 'closed')->first();

    expect($status->nextStatuses)->toBeEmpty();
});

// -- TransitionManager Tests --

test('transition manager loads available transitions from a status', function (): void {
    $manager = app(TransitionManager::class);
    $transitions = $manager->getAvailableTransitions(WF_TEST_FLOW, 'resolved');

    expect($transitions)->toHaveCount(2);
    expect($transitions->pluck('to_code')->all())->toBe(['closed', 'open']);
});

test('transition manager finds a specific transition', function (): void {
    $manager = app(TransitionManager::class);
    $transition = $manager->getTransition(WF_TEST_FLOW, 'open', 'in_progress');

    expect($transition)->not->toBeNull();
    expect($transition->label)->toBe(WF_TRANSITION_START_WORK);
});

test('transition manager returns null for non-existent transition', function (): void {
    $manager = app(TransitionManager::class);
    expect($manager->getTransition(WF_TEST_FLOW, 'open', 'closed'))->toBeNull();
});

// -- TransitionValidator Tests --

test('validator allows transition without capability or guard', function (): void {
    $validator = app(TransitionValidator::class);
    $actor = createTestActor();
    $transition = StatusTransition::query()
        ->where('flow', WF_TEST_FLOW)->where('from_code', 'open')->where('to_code', 'in_progress')->first();

    $result = $validator->validate($transition, $actor);

    expect($result->allowed)->toBeTrue();
});

test('validator denies inactive transition', function (): void {
    $transition = StatusTransition::query()
        ->where('flow', WF_TEST_FLOW)->where('from_code', 'open')->where('to_code', 'in_progress')->first();
    $transition->update(['is_active' => false]);

    $validator = app(TransitionValidator::class);
    $actor = createTestActor();
    $result = $validator->validate($transition->fresh(), $actor);

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toContain('inactive');
});

test('validator denies transition when actor lacks required capability', function (): void {
    setupAuthzRoles();

    $transition = StatusTransition::query()
        ->where('flow', WF_TEST_FLOW)->where('from_code', 'open')->where('to_code', 'in_progress')->first();
    $transition->update(['capability' => 'workflow.test_ticket.start_work']);

    $validator = app(TransitionValidator::class);
    $actor = createTestActor();
    $result = $validator->validate($transition->fresh(), $actor);

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toContain('capability');
});

test('validator allows transition when actor has required capability', function (): void {
    $user = createAdminUser(); // core_admin has grant_all

    $transition = StatusTransition::query()
        ->where('flow', WF_TEST_FLOW)->where('from_code', 'open')->where('to_code', 'in_progress')->first();
    // Use a capability registered in Config/authz.php so KnownCapabilityPolicy passes
    $transition->update(['capability' => 'workflow.process.manage']);

    $actor = Actor::forUser($user);
    $validator = app(TransitionValidator::class);
    $result = $validator->validate($transition->fresh(), $actor);

    expect($result->allowed)->toBeTrue();
});

// -- StatusHistory Model Tests --

test('history timeline returns entries in chronological order', function (): void {
    $now = now();

    StatusHistory::query()->create([
        'flow' => WF_TEST_FLOW, 'flow_id' => 1, 'status' => 'open',
        'actor_id' => 1, 'transitioned_at' => $now->copy()->subMinutes(30),
    ]);
    StatusHistory::query()->create([
        'flow' => WF_TEST_FLOW, 'flow_id' => 1, 'status' => 'in_progress',
        'actor_id' => 1, 'tat' => 1800, 'transitioned_at' => $now,
    ]);

    $timeline = StatusHistory::timeline(WF_TEST_FLOW, 1);

    expect($timeline)->toHaveCount(2);
    expect($timeline->first()->status)->toBe('open');
    expect($timeline->last()->status)->toBe('in_progress');
    expect($timeline->last()->tat)->toBe(1800);
});

test('history latest returns the most recent entry', function (): void {
    $now = now();

    StatusHistory::query()->create([
        'flow' => WF_TEST_FLOW, 'flow_id' => 42, 'status' => 'open',
        'actor_id' => 1, 'transitioned_at' => $now->copy()->subHour(),
    ]);
    StatusHistory::query()->create([
        'flow' => WF_TEST_FLOW, 'flow_id' => 42, 'status' => 'in_progress',
        'actor_id' => 1, 'transitioned_at' => $now,
    ]);

    $latest = StatusHistory::latest(WF_TEST_FLOW, 42);

    expect($latest)->not->toBeNull();
    expect($latest->status)->toBe('in_progress');
});

// -- GuardResult & TransitionResult DTO Tests --

test('guard result allow creates an allowed result', function (): void {
    $result = GuardResult::allow();

    expect($result->allowed)->toBeTrue();
    expect($result->reason)->toBeNull();
});

test('guard result deny creates a denied result with reason', function (): void {
    $result = GuardResult::deny('Insufficient leave balance');

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('Insufficient leave balance');
});

// -- StatusTransition Model Tests --

test('transition resolve label falls back to target status label', function (): void {
    $transition = StatusTransition::query()
        ->where('flow', WF_TEST_FLOW)->where('from_code', 'open')->where('to_code', 'in_progress')->first();

    // With explicit label
    expect($transition->resolveLabel())->toBe(WF_TRANSITION_START_WORK);

    // Without explicit label
    $transition->label = null;
    expect($transition->resolveLabel())->toBe(WF_STATUS_IN_PROGRESS);
});

// -- WorkflowEngine Integration Tests (using real Ticket model) --

test('engine transitions a ticket and records history', function (): void {
    $actor = createTestActor();
    $ticket = createTestTicket($actor);
    $engine = app(WorkflowEngine::class);

    $context = new TransitionContext(actor: $actor, comment: 'Assigning to IT team');
    $result = $engine->transition($ticket, 'test_ticket', 'in_progress', $context);

    expect($result->success)->toBeTrue();
    expect($result->history)->not->toBeNull();
    expect($result->history->status)->toBe('in_progress');
    expect($result->history->comment)->toBe('Assigning to IT team');
    expect($result->history->actor_id)->toBe($actor->id);

    // Model status is updated in DB
    expect($ticket->fresh()->getAttribute('status'))->toBe('in_progress');
});

test('engine records TAT between consecutive transitions', function (): void {
    $actor = createTestActor();
    $ticket = createTestTicket($actor);
    $engine = app(WorkflowEngine::class);

    // First transition: open → in_progress
    $context = new TransitionContext(actor: $actor);
    $result1 = $engine->transition($ticket, 'test_ticket', 'in_progress', $context);
    expect($result1->success)->toBeTrue();
    expect($result1->history->tat)->toBeNull(); // first transition — no previous history

    // Second transition: in_progress → resolved
    $result2 = $engine->transition($ticket, 'test_ticket', 'resolved', $context);
    expect($result2->success)->toBeTrue();
    expect($result2->history->tat)->toBeInt();
    expect($result2->history->tat)->toBeGreaterThanOrEqual(0);
});

test('engine rejects transition when no edge exists', function (): void {
    $actor = createTestActor();
    $ticket = createTestTicket($actor);
    $engine = app(WorkflowEngine::class);

    $context = new TransitionContext(actor: $actor);
    $result = $engine->transition($ticket, 'test_ticket', 'closed', $context);

    expect($result->success)->toBeFalse();
    expect($result->reason)->toContain('No transition defined');
    expect($ticket->fresh()->getAttribute('status'))->toBe('open'); // unchanged
});

test('engine dispatches TransitionCompleted event after successful transition', function (): void {
    Event::fake([TransitionCompleted::class]);

    $actor = createTestActor();
    $ticket = createTestTicket($actor);
    $engine = app(WorkflowEngine::class);

    $context = new TransitionContext(actor: $actor);
    $engine->transition($ticket, 'test_ticket', 'in_progress', $context);

    Event::assertDispatched(TransitionCompleted::class, function (TransitionCompleted $event) {
        return $event->flow === 'test_ticket' && $event->history->status === 'in_progress';
    });
});

test('HasWorkflowStatus trait provides transition shorthand on model', function (): void {
    $actor = createTestActor();
    $ticket = createTestTicket($actor);

    $context = new TransitionContext(actor: $actor, comment: 'Starting work');
    $result = $ticket->transitionTo('in_progress', $context);

    expect($result->success)->toBeTrue();
    expect($ticket->fresh()->getAttribute('status'))->toBe('in_progress');
});

test('HasWorkflowStatus trait returns status timeline', function (): void {
    $actor = createTestActor();
    $ticket = createTestTicket($actor);

    $ticket->transitionTo('in_progress', new TransitionContext(actor: $actor));
    $ticket->transitionTo('resolved', new TransitionContext(actor: $actor, comment: 'Fixed'));

    $timeline = $ticket->statusTimeline();

    expect($timeline)->toHaveCount(2);
    expect($timeline->first()->status)->toBe('in_progress');
    expect($timeline->last()->status)->toBe('resolved');
    expect($timeline->last()->comment)->toBe('Fixed');
});
