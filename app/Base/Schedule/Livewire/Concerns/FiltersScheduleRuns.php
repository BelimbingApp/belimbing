<?php

namespace App\Base\Schedule\Livewire\Concerns;

use App\Base\Schedule\DTO\RecordedRun;
use Illuminate\Support\Carbon;

trait FiltersScheduleRuns
{
    private function historyRunMatchesFilters(RecordedRun $run, string $search, string $status, Carbon $from, Carbon $to): bool
    {
        $startedAt = $run->startedAt->getTimestamp();

        return ($search === '' || str_contains(mb_strtolower($run->name), $search))
            && ($status === 'all' || $run->status === $status)
            && $startedAt >= $from->getTimestamp()
            && $startedAt <= $to->getTimestamp();
    }
}
