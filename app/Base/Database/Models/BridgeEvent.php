<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;

class BridgeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'base_database_bridge_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
