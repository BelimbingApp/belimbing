<?php

use App\Base\Workflow\Livewire\Workflows\Show;
use App\Base\Workflow\Models\StatusConfig;
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
