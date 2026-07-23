<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The latest successful Local/remote row observation for one table on one
 * endpoint pair. Keyed by (local instance, remote instance, table) so endpoint
 * changes never surface counts from a different mirror.
 *
 * @property string $local_instance_id
 * @property string $remote_instance_id
 * @property string $table_name
 */
class DataShareMirrorObservation extends Model
{
    protected $table = 'base_database_data_share_observations';

    protected $fillable = [
        'local_instance_id',
        'remote_instance_id',
        'table_name',
        'local_rows',
        'remote_rows',
        'run_id',
        'acknowledged_generation',
        'observed_at',
    ];

    protected $casts = [
        'local_rows' => 'integer',
        'remote_rows' => 'integer',
        'run_id' => 'integer',
        'acknowledged_generation' => 'integer',
        'observed_at' => 'datetime',
    ];
}
