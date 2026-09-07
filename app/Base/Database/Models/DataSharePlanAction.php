<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $id
 * @property int|null $plan_id
 * @property int|null $sequence
 * @property string|null $scope_name
 * @property string|null $table_name
 * @property string|null $primary_key_hash
 * @property array<string, mixed>|null $primary_key
 * @property string|null $action
 * @property string|null $incoming_fingerprint
 * @property string|null $destination_fingerprint
 */
class DataSharePlanAction extends Model
{
    public $timestamps = false;

    protected $table = 'base_database_data_share_plan_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'primary_key' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DataSharePlan::class, 'plan_id');
    }
}
