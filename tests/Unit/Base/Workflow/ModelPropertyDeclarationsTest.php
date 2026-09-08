<?php

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves every Workflow migration column as a declared @property on its model', function (): void {
    $families = [
        Workflow::class => [
            'id', 'code', 'label', 'module', 'description', 'model_class', 'settings', 'is_active', 'created_at', 'updated_at',
        ],
        StatusConfig::class => [
            'id', 'flow', 'code', 'label', 'pic', 'notifications', 'position', 'comment_tags', 'prompt', 'kanban_code', 'is_active', 'created_at', 'updated_at',
        ],
        StatusTransition::class => [
            'id', 'flow', 'from_code', 'to_code', 'label', 'capability', 'guard_class', 'action_class', 'sla_seconds', 'metadata', 'position', 'is_active', 'created_at', 'updated_at',
        ],
        StatusHistory::class => [
            'id', 'flow', 'flow_id', 'status', 'tat', 'actor_id', 'actor_type', 'actor_role', 'actor_department', 'actor_company', 'assignees', 'comment', 'comment_tag', 'attachments', 'metadata', 'transitioned_at', 'created_at',
        ],
        KanbanColumn::class => [
            'id', 'flow', 'code', 'label', 'position', 'wip_limit', 'settings', 'description', 'is_active', 'created_at', 'updated_at',
        ],
    ];

    foreach ($families as $class => $columns) {
        $doc = (new ReflectionClass($class))->getDocComment();
        expect($doc)->not->toBeFalse();

        foreach ($columns as $column) {
            expect($doc)->toMatch('/@property(?:-read)?\s+.+\s+\$'.preg_quote($column, '/').'\b/');
        }
    }
});

it('treats a Workflow with no persisted row as unsaved for audit subject', function (): void {
    $workflow = new Workflow([
        'code' => 'fixture',
        'label' => 'Fixture',
    ]);

    expect($workflow->exists)->toBeFalse()
        ->and($workflow->getAuditSubject())->toBeNull();
});

it('falls back to the transition to_code when no target status row exists', function (): void {
    $flow = 'fixture_'.uniqid();

    Workflow::query()->create([
        'code' => $flow,
        'label' => 'Fixture flow',
        'is_active' => true,
    ]);

    StatusConfig::query()->create([
        'flow' => $flow,
        'code' => 'done',
        'label' => 'Done',
        'position' => 1,
        'is_active' => true,
    ]);

    $resolved = StatusTransition::query()->create([
        'flow' => $flow,
        'from_code' => 'start',
        'to_code' => 'done',
        'label' => null,
        'position' => 1,
        'is_active' => true,
    ]);

    // `base_workflow_status_configs.label` is NOT NULL, so "the target status
    // has no label" is unreachable and the old test name promised more than it
    // could deliver. The only reachable fallback is a missing target row.
    $missing = StatusTransition::query()->create([
        'flow' => $flow,
        'from_code' => 'start',
        'to_code' => 'no_such_status',
        'label' => null,
        'position' => 2,
        'is_active' => true,
    ]);

    expect($resolved->resolveLabel())->toBe('Done')
        ->and($missing->resolveLabel())->toBe('no_such_status');
});

it('keeps the audit subject resolvable after a workflow row is deleted', function (): void {
    // opus-5-low's Finding 1 on #908. Eloquent clears `exists` before firing
    // `deleted`, and the global audit MutationListener resolves the subject on
    // that event, so an `exists` guard wrote a null subject for every delete.
    // The unsaved case above does not reach this one: its id is null, which the
    // old guard handled too.
    $workflow = Workflow::query()->create([
        'code' => 'deleted_'.uniqid(),
        'label' => 'Deleted flow',
        'is_active' => true,
    ]);
    $id = (int) $workflow->id;

    $workflow->delete();

    expect($workflow->exists)->toBeFalse()
        ->and($workflow->getAuditSubject())->toBe(['name' => 'workflow', 'id' => $id]);
});
