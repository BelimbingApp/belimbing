<?php

namespace App\Base\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A paused Laravel scheduler entry, keyed by its recorder-normalized name.
 * Row present = the entry is skipped at run time (see ServiceProvider's
 * CommandStarting hook); deleting the row resumes it.
 */
class ScheduleSuppression extends Model
{
    protected $table = 'base_schedule_suppressions';

    protected $fillable = ['name'];
}
