<?php

use App\Base\Workflow\Livewire\Workflows\Show;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use Livewire\Livewire;

it('saves workflow status lists from shared edit-in-place fields', function (): void {
    $workflow = Workflow::query()->create([
        'code' => 'test_flow',
        'label' => 'Test Flow',
        'is_active' => true,
    ]);

    $status = StatusConfig::query()->create([
        'flow' => $workflow->code,
        'code' => 'open',
        'label' => 'Open',
        'position' => 1,
        'is_active' => true,
    ]);

    Livewire::test(Show::class, ['workflow' => $workflow])
        ->call('saveStatusListField', $status->id.'|pic', ' reporter, assignee ,, ')
        ->call('saveStatusListField', $status->id.'|notifications', 'reporter, quality');

    $status->refresh();

    expect($status->pic)->toBe(['reporter', 'assignee'])
        ->and($status->notifications)->toBe([
            'on_enter' => ['reporter', 'quality'],
            'channels' => ['database'],
        ]);
});

it('renders the record history trigger on workflow detail pages', function (): void {
    $actor = createAdminUser();

    $workflow = Workflow::query()->create([
        'code' => 'history_flow',
        'label' => 'History Flow',
        'is_active' => true,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.workflows.show', $workflow))
        ->assertOk()
        ->assertSee('History')
        ->assertSeeHtml('wire:click="open"');
});

it('renders the workflow graph as the first detail tab', function (): void {
    $actor = createAdminUser();

    $workflow = Workflow::query()->create([
        'code' => 'approval_flow',
        'label' => 'Approval Flow',
        'is_active' => true,
    ]);

    StatusConfig::query()->create([
        'flow' => $workflow->code,
        'code' => 'draft',
        'label' => 'Draft',
        'position' => 1,
        'is_active' => true,
    ]);

    StatusConfig::query()->create([
        'flow' => $workflow->code,
        'code' => 'approved',
        'label' => 'Approved',
        'position' => 2,
        'is_active' => true,
    ]);

    StatusTransition::query()->create([
        'flow' => $workflow->code,
        'from_code' => 'draft',
        'to_code' => 'approved',
        'label' => 'Approve',
        'position' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.workflows.show', $workflow))
        ->assertOk()
        ->assertSeeInOrder(['Graph', 'Statuses', 'Transitions'])
        ->assertSee('State machine')
        ->assertSee('blbWorkflowGraph')
        ->assertSee('Draft')
        ->assertSee('Approved')
        ->assertSee('Approve')
        ->assertSeeHtml("defaultTab: 'graph'");
});

it('keeps missing transition endpoints visible in the graph', function (): void {
    $actor = createAdminUser();

    $workflow = Workflow::query()->create([
        'code' => 'drifted_flow',
        'label' => 'Drifted Flow',
        'is_active' => true,
    ]);

    StatusConfig::query()->create([
        'flow' => $workflow->code,
        'code' => 'draft',
        'label' => 'Draft',
        'position' => 1,
        'is_active' => true,
    ]);

    StatusTransition::query()->create([
        'flow' => $workflow->code,
        'from_code' => 'draft',
        'to_code' => 'removed_status',
        'label' => 'Continue',
        'position' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.workflows.show', $workflow))
        ->assertOk()
        ->assertSee('removed_status')
        ->assertSee('Missing');
});

it('renders a useful graph empty state when no statuses exist', function (): void {
    $actor = createAdminUser();

    $workflow = Workflow::query()->create([
        'code' => 'empty_flow',
        'label' => 'Empty Flow',
        'is_active' => true,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.workflows.show', $workflow))
        ->assertOk()
        ->assertSee('No statuses configured.')
        ->assertSee('Add statuses and transitions to see this workflow as a state machine.');
});
