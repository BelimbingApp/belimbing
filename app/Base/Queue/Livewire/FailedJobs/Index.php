<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Livewire\FailedJobs;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class Index extends SearchablePaginatedList
{
    public function retryJob(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    public function retryAll(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
    }

    public function deleteJob(int $id): void
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
    }

    protected function query(): EloquentBuilder|QueryBuilder
    {
        return DB::table('failed_jobs');
    }

    protected function viewName(): string
    {
        return 'livewire.admin.system.failed-jobs.index';
    }

    protected function viewDataKey(): string
    {
        return 'failedJobs';
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function ($builder) use ($search): void {
            $builder->where('queue', 'like', '%'.$search.'%')
                ->orWhere('uuid', 'like', '%'.$search.'%')
                ->orWhere('exception', 'like', '%'.$search.'%');
        });
    }

    protected function sortQuery(EloquentBuilder|QueryBuilder $query): void
    {
        $query->orderByDesc('failed_at');
    }
}
