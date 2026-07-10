<?php

namespace App\Base\Queue\Services;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Database\Exceptions\DevelopmentSanitizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PendingQueueDevelopmentSanitizer implements DevelopmentSanitizationContributor
{
    public function key(): string
    {
        return 'pending-queue';
    }

    public function preview(): DevelopmentSanitizationResult
    {
        $this->guardTables();

        return $this->result(
            DB::table('jobs')->count() + DB::table('job_batches')->count(),
        );
    }

    public function apply(): DevelopmentSanitizationResult
    {
        $this->guardTables();

        $affected = DB::table('jobs')->delete();
        $affected += DB::table('job_batches')->delete();

        return $this->result($affected);
    }

    private function guardTables(): void
    {
        foreach (['jobs', 'job_batches'] as $table) {
            if (! Schema::hasTable($table)) {
                throw DevelopmentSanitizationException::missingTable($table);
            }
        }
    }

    private function result(int $affected): DevelopmentSanitizationResult
    {
        return new DevelopmentSanitizationResult(
            key: $this->key(),
            label: __('Pending queue work'),
            affected: $affected,
            detail: __('Remove restored pending jobs and batches before a development queue worker can execute production work; failed-job history stays available for diagnosis.'),
        );
    }
}
