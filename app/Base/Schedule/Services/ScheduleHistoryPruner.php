<?php

namespace App\Base\Schedule\Services;

use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ScheduleHistoryPruner
{
    public const KEEP_DAYS_KEY = 'schedule.history.keep_days';

    public const DEFAULT_KEEP_DAYS = 90;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{deleted: int, keep_days: int}
     */
    public function prune(): array
    {
        if (! Schema::hasTable('base_schedule_runs')) {
            return ['deleted' => 0, 'keep_days' => $this->keepDays()];
        }

        $keepDays = $this->keepDays();

        if ($keepDays <= 0) {
            return ['deleted' => 0, 'keep_days' => $keepDays];
        }

        $cutoff = Carbon::now()->subDays($keepDays);

        return [
            'deleted' => ScheduleRun::query()
                ->where(function ($query) use ($cutoff): void {
                    $query->where(function ($inner) use ($cutoff): void {
                        $inner->whereNotNull('finished_at')
                            ->where('finished_at', '<', $cutoff);
                    })->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('finished_at')
                            ->where('started_at', '<', $cutoff);
                    });
                })
                ->delete(),
            'keep_days' => $keepDays,
        ];
    }

    public function keepDays(): int
    {
        return max(0, (int) $this->settings->get(self::KEEP_DAYS_KEY));
    }
}
