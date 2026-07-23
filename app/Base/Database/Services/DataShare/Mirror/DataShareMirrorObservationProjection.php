<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Models\DataShareMirrorObservation;
use Illuminate\Support\Facades\Schema;

/**
 * Maintains the current observation projection: the latest successful Local and
 * remote row counts per endpoint pair + table. Updated in place after a
 * completed mirror operation so the catalog can render counts on every request
 * without re-scanning the endpoints, and so refresh never destroys them.
 *
 * Counts are last observed, not verified equality — native transfer observes
 * endpoint counts after its external transaction.
 */
final class DataShareMirrorObservationProjection
{
    private const TABLE = 'base_database_data_share_observations';

    public function record(
        string $localInstanceId,
        string $remoteInstanceId,
        string $table,
        int $runId,
        ?int $localRows,
        ?int $remoteRows,
        ?int $acknowledgedGeneration = null,
    ): void {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $values = [
            'local_rows' => $localRows,
            'remote_rows' => $remoteRows,
            'run_id' => $runId,
            'observed_at' => now(),
        ];

        // A push acknowledges only the Local generation captured for its snapshot.
        // Baseline and non-tracking observations pass null and leave it untouched.
        if ($acknowledgedGeneration !== null) {
            $values['acknowledged_generation'] = $acknowledgedGeneration;
        }

        DataShareMirrorObservation::query()->updateOrCreate(
            [
                'local_instance_id' => $localInstanceId,
                'remote_instance_id' => $remoteInstanceId,
                'table_name' => $table,
            ],
            $values,
        );
    }
}
