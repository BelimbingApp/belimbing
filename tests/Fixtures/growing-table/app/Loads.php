<?php

namespace GrowingTableFixture;

use GrowingTableFixture\Models\RunLog;
use GrowingTableFixture\Models\RunLogLine;
use GrowingTableFixture\Models\Task;
use Illuminate\Support\Facades\DB;

final class Loads
{
    public function unbounded(RunLog $log, Task $task): void
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
        RunLog::query()->where('task_id', $task->id)->get();
        RunLog::query()->whereBelongsTo($task)->get();
        RunLog::query()->select('key', DB::raw('max(id) as id'))->groupBy('key')->get();
        RunLog::query()->distinct()->pluck('key');
        RunLog::query()->fromSub(RunLog::query()->toBase(), 'ranked')->where('rn', 1)->get();
        RunLogLine::query()->where('run_log_id', 1)->get();
        $log->lines()->get();
    }

    public function bounded(Task $task): void
    {
        RunLog::query()->limit(10)->get();
        RunLog::query()->orderByDesc('id')->take(1)->get();
        RunLog::query()->forPage(2, 50)->get();
        RunLog::query()->whereKey([1, 2, 3])->get();
        RunLog::whereKey([1])->get();
        RunLog::limit(10)->get();
        RunLog::paginate()->pluck('id');
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
