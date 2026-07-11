<?php

namespace App\Base\Database\Models;

use Illuminate\Database\Eloquent\Model;

class DataShareEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'base_database_data_share_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
