<?php

namespace GrowingTableFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Task extends Model
{
    /** @return HasMany<RunLog, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(RunLog::class);
    }
}
