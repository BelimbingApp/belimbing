<?php

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Models\AiRun;

class AiRunDurations
{
    public function activeMilliseconds(AiRun $run): ?int
    {
        $firstCall = $run->calls()
            ->whereNotNull('started_at')
            ->reorder()
            ->orderBy('started_at')
            ->first(['started_at']);

        $lastCall = $run->calls()
            ->whereNotNull('finished_at')
            ->reorder()
            ->orderByDesc('finished_at')
            ->first(['finished_at']);

        if ($firstCall?->started_at === null || $lastCall?->finished_at === null) {
            return null;
        }

        return max(0, (int) $firstCall->started_at->diffInMilliseconds($lastCall->finished_at));
    }
}
