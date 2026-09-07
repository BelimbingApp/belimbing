<?php

namespace App\Base\Database\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $id
 * @property int|null $receipt_id
 * @property string|null $plan_hash
 * @property string|null $package_sha256
 * @property string|null $destination_fingerprint
 * @property array<string, mixed>|null $summary
 * @property string|null $status
 * @property CarbonInterface|null $planned_at
 * @property CarbonInterface|null $applied_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
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
