<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BridgeReceiveGrant extends Model
{
    protected $table = 'base_database_bridge_receive_grants';

    protected $guarded = [];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(BridgeReceipt::class, 'receive_grant_id');
    }
}
