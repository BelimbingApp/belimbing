<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Livewire\Jobs;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Index extends SearchablePaginatedList
{
    public function deleteJob(int $id): void
    {
        DB::table('jobs')->where('id', $id)->delete();
    }

    protected function query(): EloquentBuilder|QueryBuilder
    {
        return DB::table('jobs');
    }

    protected function viewName(): string
    {
        return 'livewire.admin.system.jobs.index';
    }

    protected function viewDataKey(): string
    {
        return 'jobs';
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function ($builder) use ($search): void {
            $builder->where('queue', 'like', '%'.$search.'%')
                ->orWhere('payload', 'like', '%'.$search.'%');
        });
    }

    protected function sortQuery(EloquentBuilder|QueryBuilder $query): void
    {
        $query->orderByDesc('id');
    }
}
