<?php

namespace App\Core\AI\Services\Scheduling;

use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Core\AI\Enums\OperationStatus;
use App\Core\AI\Enums\OperationType;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Models\ScheduleDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Surfaces AI schedule definitions and their scheduled-task dispatches on
 * the central Schedule page (Base\Schedule). Read-only projection;
 * editing stays in the AI module's own tools.
 */
class ScheduleDefinitionContributor implements ScheduleContributor
{
    public function tasks(): array
    {
        if (! Schema::hasTable('ai_schedule_definitions')) {
            return [];
        }

        $definitions = ScheduleDefinition::query()
            ->where('is_enabled', true)
            ->orderBy('next_due_at')
            ->limit(50)
            ->get();

        $latestBySchedule = $this->latestDispatchesBySchedule($definitions->pluck('id')->all());

        return $definitions
            ->map(function (ScheduleDefinition $definition) use ($latestBySchedule): ScheduleTask {
                $latest = $latestBySchedule[$definition->id] ?? null;

                return new ScheduleTask(
                    source: $definition->source,
                    key: $definition->source.':'.(string) $definition->getKey(),
                    name: (string) ($definition->source_key ?: $definition->description),
                    cron: (string) $definition->cron_expression,
                    nextRunAt: $definition->next_due_at === null ? null : Carbon::parse($definition->next_due_at),
                    status: $this->statusValue($latest?->status),
                    lastRunAt: $latest?->started_at,
                    lastFinishedAt: $latest?->finished_at,
                    lastResult: $latest === null ? null : $this->dispatchDetail($latest),
                    url: $this->urlFor($definition),
                );
            })
            ->all();
    }

    public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage
    {
        if (! Schema::hasTable('ai_operation_dispatches')) {
            return new ScheduleHistoryPage([], 0, false);
        }

        $builder = OperationDispatch::query()
            ->whereIn('operation_type', [OperationType::ScheduledTask, OperationType::HeadlessTask])
            ->where(function ($q) use ($query): void {
                $q->where(function ($inner) use ($query): void {
                    $inner->whereNotNull('started_at')
                        ->where('started_at', '>=', $query->from)
                        ->where('started_at', '<=', $query->to);
                })->orWhere(function ($inner) use ($query): void {
                    $inner->whereNull('started_at')
                        ->where('created_at', '>=', $query->from)
                        ->where('created_at', '<=', $query->to);
                });
            });

        if ($query->status !== 'all') {
            $status = OperationStatus::tryFrom($query->status);

            if ($status === null) {
                // This source never records the requested status.
                return new ScheduleHistoryPage([], 0, $this->hasHistory());
            }

            $builder->where('status', $status);
        }

        $hasHistory = $this->hasHistory();

        // Schedule names and sources live in the dispatch JSON metadata. Keep
        // the projection in the database for filtering, ordering, counting,
        // and limiting; materializing the operation ledger in PHP makes the
        // board's pagination dishonest and unbounded. The explicit grammar
        // variants retain the same metadata fallback order on every supported
        // database without relying on one vendor's JSON syntax.
        $driver = $builder->getQuery()->getConnection()->getDriverName();
        $name = $this->historyNameExpression($driver);
        $source = $this->historySourceExpression($driver);

        if ($query->search !== '') {
            $builder->whereRaw("LOWER({$name}) LIKE ? ESCAPE ?", [$this->historySearchPattern($query->search), '\\']);
        }

        $total = (clone $builder)->count();
        $this->orderHistory($builder, $query, $name, $source);

        $dispatches = $builder
            ->select('ai_operation_dispatches.*')
            ->selectRaw("{$name} as schedule_history_name")
            ->selectRaw("{$source} as schedule_history_source")
            ->limit(max(1, $limit))
            ->get();

        return new ScheduleHistoryPage(
            $dispatches
                ->map(fn (OperationDispatch $dispatch): RecordedRun => new RecordedRun(
                    source: (string) $dispatch->getAttribute('schedule_history_source'),
                    name: (string) $dispatch->getAttribute('schedule_history_name'),
                    status: $this->statusValue($dispatch->status) ?? 'unknown',
                    startedAt: $dispatch->started_at ?? $dispatch->created_at ?? now(),
                    finishedAt: $dispatch->finished_at,
                    detail: $this->dispatchDetail($dispatch),
                ))
                ->all(),
            $total,
            $hasHistory,
        );
    }

    private function hasHistory(): bool
    {
        if (! Schema::hasTable('ai_operation_dispatches')) {
            return false;
        }

        return OperationDispatch::query()
            ->whereIn('operation_type', [OperationType::ScheduledTask, OperationType::HeadlessTask])
            ->exists();
    }

    private function historyNameExpression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "COALESCE(meta->>'source_key', meta->>'schedule_source_key', meta->>'schedule_description', task, id)",
            'mysql', 'mariadb' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source_key')), JSON_UNQUOTE(JSON_EXTRACT(meta, '$.schedule_source_key')), JSON_UNQUOTE(JSON_EXTRACT(meta, '$.schedule_description')), task, id)",
            default => "COALESCE(json_extract(meta, '$.source_key'), json_extract(meta, '$.schedule_source_key'), json_extract(meta, '$.schedule_description'), task, id)",
        };
    }

    private function historySourceExpression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "COALESCE(meta->>'source', meta->>'schedule_source', 'ai-agent')",
            'mysql', 'mariadb' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')), JSON_UNQUOTE(JSON_EXTRACT(meta, '$.schedule_source')), 'ai-agent')",
            default => "COALESCE(json_extract(meta, '$.source'), json_extract(meta, '$.schedule_source'), 'ai-agent')",
        };
    }

    private function historySearchPattern(string $search): string
    {
        return '%'.str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $search,
        ).'%';
    }

    private function orderHistory(Builder $builder, ScheduleHistoryQuery $query, string $name, string $source): void
    {
        $direction = $query->sortDirection === 'asc' ? 'ASC' : 'DESC';
        $startedAt = 'COALESCE(started_at, created_at)';
        $primary = match ($query->sortColumn) {
            'name' => "LOWER({$name})",
            'source' => "LOWER({$source})",
            'status' => 'status',
            default => $startedAt,
        };

        $builder
            ->orderByRaw("{$primary} {$direction}")
            ->orderByRaw("{$startedAt} DESC")
            ->orderByRaw("LOWER({$source}) ASC")
            ->orderByRaw("LOWER({$name}) ASC")
            ->orderBy('id');
    }

    private function dispatchDetail(OperationDispatch $dispatch): ?string
    {
        $provider = $dispatch->meta['headless_provider'] ?? null;
        $model = $dispatch->meta['headless_model'] ?? null;
        $identity = match (true) {
            is_string($provider) && $provider !== '' && is_string($model) && $model !== '' => $provider.'/'.$model,
            is_string($provider) && $provider !== '' => $provider,
            is_string($model) && $model !== '' => $model,
            default => '',
        };
        $detail = $dispatch->result_summary ?? $dispatch->error_message ?? $dispatch->task;

        if ($identity !== '' && is_string($detail) && trim($detail) !== '') {
            $detail = $identity.' - '.$detail;
        }

        return is_string($detail) && trim($detail) !== '' ? mb_substr(trim($detail), 0, 300) : null;
    }

    /**
     * @param  list<int|string>  $scheduleIds
     * @return array<int, OperationDispatch|null>
     */
    private function latestDispatchesBySchedule(array $scheduleIds): array
    {
        if ($scheduleIds === [] || ! Schema::hasTable('ai_operation_dispatches')) {
            return [];
        }

        $latest = [];

        OperationDispatch::query()
            ->whereIn('operation_type', [OperationType::ScheduledTask, OperationType::HeadlessTask])
            ->whereIn('meta->schedule_id', $scheduleIds)
            ->orderByDesc('created_at')
            ->get()
            ->each(function (OperationDispatch $dispatch) use (&$latest): void {
                $scheduleId = data_get($dispatch->meta, 'schedule_id');

                if (is_int($scheduleId) || (is_string($scheduleId) && ctype_digit($scheduleId))) {
                    $latest[(int) $scheduleId] ??= $dispatch;
                }
            });

        return $latest;
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return strtolower((string) $status->value);
        }

        return is_string($status) && trim($status) !== '' ? strtolower($status) : null;
    }

    private function urlFor(ScheduleDefinition $definition): ?string
    {
        $route = data_get($definition->meta, 'route');

        return is_string($route) && $route !== '' && app('router')->has($route)
            ? route($route)
            : null;
    }
}
