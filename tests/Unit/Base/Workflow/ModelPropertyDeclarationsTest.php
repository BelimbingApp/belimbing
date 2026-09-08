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

it('renders a transition label when the target status has none', function (): void {
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

    $transition = StatusTransition::query()->create([
        'flow' => $flow,
        'from_code' => 'start',
        'to_code' => 'done',
        'label' => null,
        'position' => 1,
        'is_active' => true,
    ]);

    expect($transition->resolveLabel())->toBe('Done');
});
