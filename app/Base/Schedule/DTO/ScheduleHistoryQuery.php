<?php

namespace App\Base\Schedule\DTO;

use Illuminate\Support\Carbon;

/**
 * The filter and ordering contract for one Schedule history query. Every
 * source — the scheduler table and each ScheduleContributor — honors the same
 * constraints before its own truncation, so the merged page is honest about
 * what it shows and what it totals.
 */
final readonly class ScheduleHistoryQuery
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $status,          // 'all' or a concrete run status
        public string $search,          // '' or a lowercased search term
        public string $sortColumn,       // started_at|name|source|status
        public string $sortDirection,    // asc|desc
    ) {}
}
