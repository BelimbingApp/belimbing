<?php

namespace App\Core\AI\Services\Scheduling;

use App\Base\Schedule\Contracts\ScheduleContributor;
use App\Base\Schedule\Contracts\ScheduleHealthContributor;
use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;
use App\Base\Schedule\DTO\UnhealthyScheduleTask;
use App\Core\AI\Enums\OperationStatus;
use App\Core\AI\Enums\OperationType;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Models\ScheduleDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surfaces AI schedule definitions and their scheduled-task dispatches on
 * the central Schedule page (Base\Schedule). Read-only projection;
 * editing stays in the AI module's own tools.
 */
class ScheduleDefinitionContributor implements ScheduleContributor, ScheduleHealthContributor
{
    private const int HEALTH_RUNS_PER_DEFINITION = 10;

    public function tasks(): array
    {
        if (! Schema::hasTable('ai_schedule_definitions')) {
            return [];
        }

        $definitions = $this->enabledDefinitions();

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

    /**
     * Return only active contributor failures for the shared status bar.
     * This deliberately avoids the full ScheduleBoard projection and reads a
     * bounded recent window per definition from the operation ledger.
     *
     * @return list<UnhealthyScheduleTask>
     */
    public function unhealthyTasks(): array
    {
        $definitions = ScheduleDefinition::query()
            ->where('is_enabled', true)
            ->orderBy('next_due_at')
            ->limit(50);
        $dispatches = $this->rankedDispatchesBySchedule();
        $rows = DB::query()
            ->fromSub($definitions->toBase(), 'health_definitions')
            ->leftJoinSub($dispatches->toBase(), 'health_dispatches', function (JoinClause $join): void {
                $join->on('health_dispatches.schedule_id_value', '=', 'health_definitions.id')
                    ->where('health_dispatches.dispatch_rank', '<=', self::HEALTH_RUNS_PER_DEFINITION);
            })
            ->select([
                'health_definitions.id as definition_id',
                'health_definitions.source',
                'health_definitions.source_key',
                'health_definitions.description',
                'health_dispatches.dispatch_status',
                'health_dispatches.dispatch_started_at',
                'health_dispatches.dispatch_created_at',
            ])
            ->orderBy('health_definitions.next_due_at')
            ->orderBy('health_dispatches.dispatch_rank')
            ->get();
        $dispatchesByDefinition = $rows->groupBy('definition_id');
        $unhealthy = [];

        foreach ($rows->pluck('definition_id')->unique() as $definitionId) {
            $definition = $dispatchesByDefinition->get($definitionId)->first();
            $task = $this->unhealthyTaskForDispatches(
                (string) $definition->source,
                (string) $definition->source_key,
                (string) $definition->description,
                (int) $definitionId,
                $dispatchesByDefinition->get($definitionId),
            );

            if ($task !== null) {
                $unhealthy[] = $task;
            }
        }

        return $unhealthy;
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
        $latest = [];

        foreach ($this->dispatchesBySchedule($scheduleIds, 1) as $scheduleId => $dispatches) {
            $latest[$scheduleId] = $dispatches[0] ?? null;
        }

        return $latest;
    }

    /**
     * @return Collection<int, ScheduleDefinition>
     */
    private function enabledDefinitions(): Collection
    {
        return ScheduleDefinition::query()
            ->where('is_enabled', true)
            ->orderBy('next_due_at')
            ->limit(50)
            ->get();
    }

    /**
     * @param  list<int|string>  $scheduleIds
     * @return array<int, list<OperationDispatch>>
     */
    private function dispatchesBySchedule(array $scheduleIds, int $perSchedule): array
    {
        if ($scheduleIds === [] || ! Schema::hasTable('ai_operation_dispatches')) {
            return [];
        }

        $query = OperationDispatch::query()
            ->whereIn('operation_type', [OperationType::ScheduledTask, OperationType::HeadlessTask])
            ->whereIn('meta->schedule_id', $scheduleIds);
        $driver = $query->getQuery()->getConnection()->getDriverName();
        $scheduleId = $this->scheduleIdExpression($driver);

        $ranked = $query
            ->select('ai_operation_dispatches.*')
            ->selectRaw("{$scheduleId} AS schedule_id_value")
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$scheduleId} ORDER BY COALESCE(started_at, created_at) DESC, id DESC) AS schedule_rank");
        $dispatches = OperationDispatch::query()
            ->fromSub($ranked->toBase(), 'recent_operation_dispatches')
            ->where('schedule_rank', '<=', max(1, $perSchedule))
            ->get();
        $grouped = [];

        foreach ($dispatches as $dispatch) {
            $scheduleIdValue = $dispatch->getAttribute('schedule_id_value');

            if (is_int($scheduleIdValue) || (is_string($scheduleIdValue) && ctype_digit($scheduleIdValue))) {
                $grouped[(int) $scheduleIdValue][] = $dispatch;
            }
        }

        return $grouped;
    }

    private function scheduleIdExpression(string $driver, string $column = 'meta'): string
    {
        return match ($driver) {
            'pgsql' => "{$column}->>'schedule_id'",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.schedule_id'))",
            default => "json_extract({$column}, '$.schedule_id')",
        };
    }

    /**
     * @param  Collection<int, object{dispatch_status: string|null, dispatch_started_at: string|null, dispatch_created_at: string|null}>  $dispatches
     */
    private function unhealthyTaskForDispatches(
        string $source,
        string $sourceKey,
        string $description,
        int $definitionId,
        Collection $dispatches,
    ): ?UnhealthyScheduleTask {
        $consecutiveFailures = 0;
        $lastAttemptAt = null;

        foreach ($dispatches as $dispatch) {
            $status = strtolower((string) $dispatch->dispatch_status);

            if ($status === OperationStatus::Failed->value) {
                $consecutiveFailures++;
                $lastAttemptAt ??= Carbon::parse($dispatch->dispatch_started_at ?? $dispatch->dispatch_created_at);

                continue;
            }

            if ($status === OperationStatus::Succeeded->value) {
                return null;
            }
        }

        if ($lastAttemptAt === null) {
            return null;
        }

        $name = $sourceKey !== '' ? $sourceKey : $description;

        return new UnhealthyScheduleTask(
            source: $source,
            key: $source.':'.$definitionId,
            name: $name,
            lastAttemptAt: $lastAttemptAt,
            consecutiveFailures: max(1, $consecutiveFailures),
        );
    }

    /**
     * @return Builder<OperationDispatch>
     */
    private function rankedDispatchesBySchedule(): Builder
    {
        $query = OperationDispatch::query()
            ->whereIn('operation_type', [OperationType::ScheduledTask, OperationType::HeadlessTask]);
        $driver = $query->getQuery()->getConnection()->getDriverName();
        $scheduleId = $this->scheduleIdExpression($driver, 'ai_operation_dispatches.meta');

        return $query
            ->whereExists(function (QueryBuilder $definitions) use ($driver, $scheduleId): void {
                $definitions
                    ->selectRaw('1')
                    ->from('ai_schedule_definitions')
                    ->where('is_enabled', true)
                    ->whereRaw($this->scheduleIdComparison($driver, $scheduleId));
            })
            ->select([
                'id',
                'status as dispatch_status',
                'started_at as dispatch_started_at',
                'created_at as dispatch_created_at',
            ])
            ->selectRaw("{$scheduleId} AS schedule_id_value")
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$scheduleId} ORDER BY COALESCE(started_at, created_at) DESC, id DESC) AS dispatch_rank");
    }

    private function scheduleIdComparison(string $driver, string $scheduleId): string
    {
        return $driver === 'pgsql'
            ? "{$scheduleId} = CAST(ai_schedule_definitions.id AS TEXT)"
            : "{$scheduleId} = ai_schedule_definitions.id";
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
