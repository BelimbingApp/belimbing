<?php

namespace App\Base\Database\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $id
 * @property string|null $offer_id
 * @property string|null $secret_hash
 * @property string|null $secret
 * @property int|null $published_by_actor_id
 * @property string|null $package_id
 * @property string|null $package_sha256
 * @property string|null $package_path
 * @property string|null $source_instance_id
 * @property string|null $source_name
 * @property string|null $source_role
 * @property string|null $scope_name
 * @property int|null $bytes
 * @property array<string, mixed>|null $metadata
 * @property string|null $status
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property int|null $download_count
 * @property int|null $max_downloads
 * @property CarbonInterface|null $last_downloaded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class DataShareTransferOffer extends Model
{
    protected $table = 'base_database_data_share_transfer_offers';

    protected $guarded = [];

    protected $hidden = ['secret_hash', 'secret'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'secret' => 'encrypted',
            'max_downloads' => 'integer',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_downloaded_at' => 'immutable_datetime',
        ];
    }
}
