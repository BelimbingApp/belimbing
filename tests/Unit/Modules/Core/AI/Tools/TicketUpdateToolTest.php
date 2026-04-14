<?php

use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Core\AI\Tools\TicketUpdateTool;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = app(TicketUpdateTool::class);
});

it('attributes ticket comments to the authenticated user employee record', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
    ]);
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
    $ticket = Ticket::query()->create([
        'company_id' => $company->id,
        'reporter_id' => $employee->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Investigate agent attribution',
    ]);

    $this->actingAs($user);

    $result = $this->tool->execute([
        'ticket_id' => $ticket->id,
        'action' => 'post_comment',
        'comment' => 'Lara is working on it.',
        'comment_tag' => 'agent_progress',
    ]);

    $entry = StatusHistory::latest('it_ticket', $ticket->id);

    expect((string) $result)->toContain("Comment posted to ticket #{$ticket->id}.");
    expect($entry)->not->toBeNull();
    expect($user->employee_id)->toBe($employee->id);
    expect($entry?->actor_id)->toBe($employee->id);
});

it('falls back to Lara attribution when no employee context is available', function () {
    $company = Company::factory()->create();
    Employee::provisionLara();

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);

    $ticket = Ticket::query()->create([
        'company_id' => $company->id,
        'reporter_id' => Employee::LARA_ID,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Investigate Lara fallback attribution',
    ]);

    $this->actingAs($user);

    $result = $this->tool->execute([
        'ticket_id' => $ticket->id,
        'action' => 'post_comment',
        'comment' => 'Research findings collected.',
        'comment_tag' => 'agent_progress',
    ]);

    $entry = StatusHistory::latest('it_ticket', $ticket->id);

    expect((string) $result)->toContain("Comment posted to ticket #{$ticket->id}.");
    expect($entry)->not->toBeNull();
    expect($entry?->actor_id)->toBe(Employee::LARA_ID);
});
