<?php

namespace Tests\Support;

use App\Core\AI\Enums\OperationStatus;
use App\Core\AI\Enums\OperationType;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Models\ScheduleDefinition;
use App\Core\Company\Models\Company;

final class ScheduleHealthFixtures
{
    /**
     * Create one enabled AI schedule and its failed operation dispatch.
     *
     * @return array{definition: ScheduleDefinition, dispatch: OperationDispatch}
     */
    public static function failedAiSchedule(string $dispatchId): array
    {
        $definition = ScheduleDefinition::query()->create([
            'company_id' => Company::factory()->create()->id,
            'source' => ScheduleDefinition::SOURCE_CORE_AI,
            'source_key' => 'nightly-summary',
            'executor' => ScheduleDefinition::EXECUTOR_AGENTIC_RUNTIME,
            'description' => 'Nightly summary',
            'execution_payload' => 'Summarize the day',
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'is_enabled' => true,
            'concurrency_policy' => 'skip',
        ]);

        $dispatch = OperationDispatch::query()->create([
            'id' => $dispatchId,
            'operation_type' => OperationType::ScheduledTask,
            'task' => 'Nightly summary',
            'status' => OperationStatus::Failed,
            'meta' => ['schedule_id' => $definition->id],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        return compact('definition', 'dispatch');
    }
}
