<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSharePlan extends Model
{
    protected $table = 'base_database_data_share_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'planned_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(DataShareReceipt::class, 'receipt_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DataSharePlanAction::class, 'plan_id')->orderBy('sequence');
    }
}
