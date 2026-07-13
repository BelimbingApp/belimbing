<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;

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
