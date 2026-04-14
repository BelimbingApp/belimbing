<?php

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentTaskPromptFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/agent-task-prompt-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

it('falls back to the generic delegated-agent prompt when a non-system agent workspace is missing system_prompt', function (): void {
    $dispatch = new OperationDispatch([
        'id' => 'op_generic_agent_prompt',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => 999,
        'acting_for_user_id' => 123,
        'task' => 'Review the latest implementation',
        'status' => OperationStatus::Queued,
    ]);

    $package = app(AgentTaskPromptFactory::class)->buildPackage($dispatch);

    expect($package->validation->valid)->toBeTrue()
        ->and($package->validation->warnings)->toContain(
            'Used the framework generic delegated-agent prompt because the agent workspace is missing system_prompt.md.'
        )
        ->and($package->sections[0]->label)->toBe('system_prompt')
        ->and($package->sections[0]->source)->toContain('Resources/agent-task/system_prompt.md')
        ->and($package->sections[0]->content)->toContain('delegated BLB agent task')
        ->and($package->sections[1]->label)->toBe('dispatch_context');
});
