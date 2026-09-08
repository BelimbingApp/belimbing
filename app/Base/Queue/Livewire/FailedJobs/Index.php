<?php

namespace App\Base\Queue\Livewire\FailedJobs;

use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Livewire\TableSearchablePaginatedList;
use App\Base\Queue\Services\ActionableFailedJobRepository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class Index extends TableSearchablePaginatedList
{
    use ChecksCapabilityAuthorization;

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

    protected function query(): EloquentBuilder|QueryBuilder
    {
        return $this->failedJobs()->query();
    }

    public function retryJob(string $uuid): void
    {
        if (! $this->checkCapability('admin.system.failed-job.manage')) {
            return;
        }

        if (! $this->failedJobs()->isRetryableUuid($uuid)) {
            return;
        }

        $exitCode = Artisan::call('queue:retry', ['id' => [$uuid]]);
        if ($exitCode !== 0 || ! $this->retryRemovedFailedJob($uuid)) {
            // queue:retry can exit non-zero, or leave the row when the id is missing (#898).
            $this->notifyError(__('Failed job could not be retried.'));

            return;
        }

        $this->recordRetried($uuid);
    }

    public function retryAll(): void
    {
        if (! $this->checkCapability('admin.system.failed-job.manage')) {
            return;
        }

        $uuids = $this->failedJobs()->retryableUuids();

        if ($uuids === []) {
            return;
        }

        $exitCode = Artisan::call('queue:retry', ['id' => $uuids]);
        if ($exitCode !== 0) {
            $this->notifyError(__('Failed jobs could not be retried.'));

            return;
        }

        // One audit row per uuid that actually left failed_jobs; skip partial misses (#898).
        $retried = 0;
        foreach ($uuids as $uuid) {
            if ($this->retryRemovedFailedJob($uuid)) {
                $this->recordRetried($uuid);
                $retried++;
            }
        }

        if ($retried === 0) {
            $this->notifyError(__('Failed jobs could not be retried.'));
        }
    }

    public function deleteJob(int $id): void
    {
        if (! $this->checkCapability('admin.system.failed-job.manage')) {
            return;
        }

        $uuid = DB::table('failed_jobs')->where('id', $id)->value('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return;
        }

        $deleted = DB::table('failed_jobs')->where('id', $id)->delete();
        if ($deleted === 0) {
            return;
        }

        app(SemanticActionRecorder::class)->record(
            event: 'queue.failed_job.deleted',
            summary: __('Deleted failed job :uuid', ['uuid' => $uuid]),
            source: __('Failed Jobs'),
            subject: ['name' => 'failed_job', 'id' => $id, 'identifier' => $uuid],
            surface: 'admin.system.failed-jobs',
            uiElement: __('Delete row action'),
            context: ['uuid' => $uuid],
        );
    }

    private function recordRetried(string $uuid): void
    {
        app(SemanticActionRecorder::class)->record(
            event: 'queue.failed_job.retried',
            summary: __('Retried failed job :uuid', ['uuid' => $uuid]),
            source: __('Failed Jobs'),
            subject: ['name' => 'failed_job', 'id' => $uuid, 'identifier' => $uuid],
            surface: 'admin.system.failed-jobs',
            uiElement: __('Retry'),
            context: ['uuid' => $uuid],
        );
    }

    /**
     * queue:retry removes a row from failed_jobs only when that id was retried.
     * Exit code 0 alone is not enough: missing ids are reported while the command continues.
     */
    private function retryRemovedFailedJob(string $uuid): bool
    {
        return ! DB::table('failed_jobs')->where('uuid', $uuid)->exists();
    }

    private function failedJobs(): ActionableFailedJobRepository
    {
        return app(ActionableFailedJobRepository::class);
    }
}
