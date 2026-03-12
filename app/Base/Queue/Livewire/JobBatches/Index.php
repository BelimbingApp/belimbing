<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Livewire\JobBatches;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Index extends SearchablePaginatedList
{
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

    protected function query(): EloquentBuilder|QueryBuilder
    {
        return DB::table('job_batches');
    }

    protected function viewName(): string
    {
        return 'livewire.admin.system.job-batches.index';
    }

    protected function viewDataKey(): string
    {
        return 'batches';
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function ($builder) use ($search): void {
            $builder->where('name', 'like', '%'.$search.'%')
                ->orWhere('id', 'like', '%'.$search.'%');
        });
    }

    protected function sortQuery(EloquentBuilder|QueryBuilder $query): void
    {
        $query->orderByDesc('created_at');
    }
}
