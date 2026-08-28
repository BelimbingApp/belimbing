<?php

namespace App\Base\Schedule\DTO;

/**
 * One page of merged schedule history: the rows for the requested page, the
 * complete filtered total across all sources, and whether any retained
 * history exists at all — the last lets the UI distinguish "no runs recorded
 * yet" from "no runs match the current filters".
 */
final readonly class ScheduleHistoryPage
{
    /**
     * @param  list<RecordedRun>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public bool $hasHistory,
    ) {}
}
