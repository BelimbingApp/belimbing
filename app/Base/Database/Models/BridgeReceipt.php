<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BridgeReceipt extends Model
{
    protected $table = 'base_database_bridge_receipts';

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
        return $this->hasMany(BridgePlan::class, 'receipt_id');
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(BridgeReceiveGrant::class, 'receive_grant_id');
    }
}
