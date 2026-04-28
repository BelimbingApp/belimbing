<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Livewire\JobBatches;

use App\Base\Foundation\Livewire\TableSearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Index extends TableSearchablePaginatedList
{
    protected const string TABLE = 'job_batches';

    protected const string VIEW_NAME = 'livewire.admin.system.job-batches.index';

    protected const string VIEW_DATA_KEY = 'batches';

    protected const string SORT_COLUMN = 'created_at';

    protected const array SEARCH_COLUMNS = ['name', 'id'];

    protected function sortableColumns(): array
    {
        return [
            'name' => 'job_batches.name',
            'total_jobs' => 'job_batches.total_jobs',
            'pending_jobs' => 'job_batches.pending_jobs',
            'failed_jobs' => 'job_batches.failed_jobs',
            'created_at' => 'job_batches.created_at',
            'cancelled_at' => 'job_batches.cancelled_at',
            'finished_at' => 'job_batches.finished_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'name' => 'asc',
            'total_jobs' => 'desc',
            'pending_jobs' => 'desc',
            'failed_jobs' => 'desc',
            'created_at' => 'desc',
            'cancelled_at' => 'desc',
            'finished_at' => 'desc',
        ];
    }

    protected function sortQuery(EloquentBuilder|QueryBuilder $query): void
    {
        if ($this->sortBy === 'cancelled_at') {
            $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';

            // cancelled > finished > in-progress (stable tie-breakers)
            $query->orderByRaw(
                'case when job_batches.cancelled_at is not null then 2 when job_batches.finished_at is not null then 1 else 0 end '.$dir
            )
                ->orderByDesc('job_batches.cancelled_at')
                ->orderByDesc('job_batches.finished_at')
                ->orderByDesc('job_batches.created_at')
                ->orderByDesc('job_batches.id');

            return;
        }

        parent::sortQuery($query);
    }

    public function cancelBatch(string $id): void
    {
        DB::table('job_batches')
            ->where('id', $id)
            ->whereNull('cancelled_at')
            ->whereNull('finished_at')
            ->update(['cancelled_at' => now()->timestamp]);
    }

    public function pruneCompleted(): void
    {
        DB::table('job_batches')
            ->whereNotNull('finished_at')
            ->delete();
    }
}
