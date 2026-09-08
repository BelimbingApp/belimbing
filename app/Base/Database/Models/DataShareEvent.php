<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only Data Share ledger row (no payload values or secrets).
 *
 * @property int $id
 * @property string|null $package_id
 * @property string|null $plan_hash
 * @property string $action
 * @property int|null $actor_id
 * @property string|null $source_instance_id
 * @property string|null $target_instance_id
 * @property string|null $scope_name
 * @property array<string, mixed>|null $metadata
 * @property string|null $error_summary
 * @property Carbon $created_at
 */
class DataShareEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'base_database_data_share_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
