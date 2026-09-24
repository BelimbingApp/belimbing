<?php

namespace GrowingTableFixture;

use GrowingTableFixture\Models\RunLog;
use GrowingTableFixture\Models\RunLogLine;
use GrowingTableFixture\Models\Task;
use Illuminate\Support\Facades\DB;

final class Loads
{
    public function unbounded(Task $task): void
    {
        RunLog::all();
        RunLog::query()->get();
        RunLog::query()->where('status', 'failed')->orderByDesc('started_at')->get();
        RunLog::where('status', 'failed')->get();
        RunLog::query()->pluck('id');
        RunLog::query()->getModels();
        $task->runs()->get();
        $task->runs()->latest()->get();
        RunLog::query()->whereIn('key', ['a', 'b'])->get();
        RunLog::query()->where('task_id', '>', 10)->get();
        RunLog::query()->whereIn('task_id', [1, 2])->get();
        RunLog::query()->where('status', 'failed')->where('company_id', 7)->get();
        RunLogLine::query()->where('run_log_id', 1)->get();
    }

    public function partitioned(RunLog $log, Task $task): void
    {
        RunLog::query()->where('task_id', $task->id)->get();
        RunLog::query()->where('status', 'failed')->where('task_id', '=', $task->id)->orderBy('id')->get();
        RunLog::query()->whereBelongsTo($log)->get();
        RunLog::query()->where('run_logs.task_id', $task->id)->get();
        $log->lines()->get();
        $log->lines()->where('level', 'error')->get();
        RunLog::query()->select('task_id', DB::raw('max(id) as id'))->groupBy('task_id')->pluck('id');
        RunLog::query()->fromSub(RunLog::query()->selectRaw('*, row_number() over (partition by task_id order by id desc) as rn')->toBase(), 'ranked')->where('rn', '<=', 3)->get();
    }

    public function bounded(Task $task): void
    {
        RunLog::query()->limit(10)->get();
        RunLog::query()->orderByDesc('id')->take(1)->get();
        RunLog::query()->forPage(2, 50)->get();
        RunLog::query()->whereKey([1, 2, 3])->get();
        RunLog::query()->select('key', DB::raw('max(id) as id'))->groupBy('key')->get();
        RunLog::query()->distinct()->pluck('key');
        $task->runs()->limit(5)->get();
        RunLog::query()->paginate();
        RunLog::query()->cursor();
        RunLog::query()->lazy();
        RunLog::query()->chunk(500, static fn () => null);
        RunLog::query()->first();
        RunLog::query()->count();
        Task::all();
        Task::query()->get();
        $task->runs()->where('status', 'running')->first();
        RunLog::query()->limit(10)->get()->all();
        RunLog::query()->where('status', 'failed')->first()?->task()->get();
        RunLog::query()->paginate()->pluck('id');
    }
}
