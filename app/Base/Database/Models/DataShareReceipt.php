<?php

namespace App\Base\Database\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $id
 * @property string|null $package_id
 * @property string|null $package_sha256
 * @property string|null $package_path
 * @property string|null $source_instance_id
 * @property string|null $source_role
 * @property string|null $target_instance_id
 * @property string|null $scope_name
 * @property string|null $offer_id
 * @property string|null $status
 * @property CarbonInterface|null $received_at
 * @property CarbonInterface|null $expires_at
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class DataShareReceipt extends Model
{
    protected $table = 'base_database_data_share_receipts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(DataSharePlan::class, 'receipt_id');
    }
}
