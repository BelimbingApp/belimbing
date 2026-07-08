<?php

namespace App\Modules\Core\AI\Services\Scheduling;

use App\Base\Scheduling\Contracts\SchedulingContributor;
use App\Base\Scheduling\DTO\RecordedRun;
use App\Base\Scheduling\DTO\UpcomingRun;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Surfaces AI schedule definitions and their scheduled-task dispatches on
 * the central Scheduling page (Base\Scheduling). Read-only projection;
 * editing stays in the AI module's own tools.
 */
class ScheduleDefinitionContributor implements SchedulingContributor
{
    public function upcoming(): array
    {
        if (! Schema::hasTable('ai_schedule_definitions')) {
            return [];
        }

        return ScheduleDefinition::query()
            ->where('is_enabled', true)
            ->orderBy('next_due_at')
            ->limit(50)
            ->get()
            ->map(fn (ScheduleDefinition $definition): UpcomingRun => new UpcomingRun(
                source: 'ai-agent',
                name: (string) $definition->description,
                cron: (string) $definition->cron_expression,
                nextRunAt: $definition->next_due_at === null ? null : Carbon::parse($definition->next_due_at),
            ))
            ->all();
    }

    public function recentRuns(int $limit): array
    {
        if (! Schema::hasTable('ai_operation_dispatches')) {
            return [];
        }

        return OperationDispatch::query()
            ->where('operation_type', OperationType::ScheduledTask)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (OperationDispatch $dispatch): RecordedRun => new RecordedRun(
                source: 'ai-agent',
                name: (string) ($dispatch->meta['schedule_description'] ?? $dispatch->task ?? $dispatch->id),
                status: strtolower($dispatch->status instanceof \BackedEnum ? (string) $dispatch->status->value : (string) $dispatch->status),
                startedAt: $dispatch->created_at,
                finishedAt: $dispatch->updated_at,
                detail: is_string($dispatch->task) ? mb_substr($dispatch->task, 0, 300) : null,
            ))
            ->all();
    }
}
