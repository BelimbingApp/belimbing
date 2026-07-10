<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BridgePlanAction extends Model
{
    public $timestamps = false;

    protected $table = 'base_database_bridge_plan_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'primary_key' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BridgePlan::class, 'plan_id');
    }
}
