<?php

namespace GrowingTableFixture\Models;

use App\Base\Database\Contracts\GrowingTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RunLog extends Model implements GrowingTable
{
    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<RunLogLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RunLogLine::class);
    }
}
