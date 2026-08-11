<?php

use App\Core\AI\Enums\OperationType;
use App\Core\AI\Jobs\RunHeadlessCliTaskJob;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Models\ScheduleDefinition;
use App\Core\AI\Services\Scheduling\SchedulePlanner;
use App\Core\Employee\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches headless schedules as Lara when no target agent is set', function (): void {
    Queue::fake();

    $operatorCompany = provisionPlatformOperatorCompany('Planner Test Company');
    Employee::provisionLara();

    $schedule = ScheduleDefinition::query()->create([
        'company_id' => $operatorCompany->id,
        'employee_id' => null,
        'source' => 'core-ai',
        'source_key' => 'headless-regression',
        'executor' => ScheduleDefinition::EXECUTOR_HEADLESS_CLI,
        'headless_provider' => 'openai',
        'headless_model' => 'gpt-5.5',
        'description' => 'Headless regression',
        'execution_payload' => 'Run the report',
        'cron_expression' => '* * * * *',
        'timezone' => 'UTC',
        'is_enabled' => true,
        'concurrency_policy' => 'skip',
        'next_due_at' => now()->subMinute(),
    ]);

    expect(app(SchedulePlanner::class)->dispatchDue())->toBe(1);

    $dispatch = OperationDispatch::query()->where('meta->schedule_id', $schedule->id)->sole();

    expect($dispatch->operation_type)->toBe(OperationType::HeadlessTask)
        ->and($dispatch->employee_id)->toBe(Employee::LARA_ID);

    Queue::assertPushed(RunHeadlessCliTaskJob::class, fn (RunHeadlessCliTaskJob $job): bool => $job->dispatchId === $dispatch->id);
});
