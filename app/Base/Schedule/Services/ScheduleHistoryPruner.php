<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Models\ScheduleRunHistory;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Carbon;

class ScheduleHistoryPruner
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Apply keep_days then keep_count. Never touches base_schedule_runs.
     *
     * @return array{deleted: int, keep_days: int, keep_count: int}
     */
    public function prune(): array
    {
        $keepDays = $this->keepDays();
        $keepCount = $this->keepCount();
        $deleted = 0;

        if ($keepDays > 0) {
            $cutoff = Carbon::now()->subDays($keepDays);
            $deleted += ScheduleRunHistory::query()
                ->where(function ($query) use ($cutoff): void {
                    $query->where(function ($inner) use ($cutoff): void {
                        $inner->whereNotNull('finished_at')
                            ->where('finished_at', '<', $cutoff);
                    })->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('finished_at')
                            ->whereNotNull('started_at')
                            ->where('started_at', '<', $cutoff);
                    })->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('finished_at')
                            ->whereNull('started_at')
                            ->where('created_at', '<', $cutoff);
                    });
                })
                ->delete();
        }

        if ($keepCount > 0) {
            $keepIds = ScheduleRunHistory::query()
                ->orderByDesc('id')
                ->limit($keepCount)
                ->pluck('id');

            if ($keepIds->isNotEmpty()) {
                $deleted += ScheduleRunHistory::query()
                    ->whereNotIn('id', $keepIds)
                    ->delete();
            }
        }

        return [
            'deleted' => $deleted,
            'keep_days' => $keepDays,
            'keep_count' => $keepCount,
        ];
    }

    public function keepDays(): int
    {
        return max(0, (int) $this->settings->get(
            'schedule.history.keep_days',
            config('schedule.history.keep_days', 30),
        ));
    }

    public function keepCount(): int
    {
        return max(0, (int) $this->settings->get(
            'schedule.history.keep_count',
            config('schedule.history.keep_count', 500),
        ));
    }
}
