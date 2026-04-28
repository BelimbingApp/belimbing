<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Livewire\FailedJobs;

use App\Base\Foundation\Livewire\TableSearchablePaginatedList;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class Index extends TableSearchablePaginatedList
{
    protected const string TABLE = 'failed_jobs';

    protected const string VIEW_NAME = 'livewire.admin.system.failed-jobs.index';

    protected const string VIEW_DATA_KEY = 'failedJobs';

    protected const string SORT_COLUMN = 'failed_at';

    protected const array SEARCH_COLUMNS = ['queue', 'uuid', 'exception'];

    protected function sortableColumns(): array
    {
        return [
            'id' => 'failed_jobs.id',
            'queue' => 'failed_jobs.queue',
            'exception' => 'failed_jobs.exception',
            'failed_at' => 'failed_jobs.failed_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'id' => 'desc',
            'queue' => 'asc',
            'exception' => 'asc',
            'failed_at' => 'desc',
        ];
    }

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
}
