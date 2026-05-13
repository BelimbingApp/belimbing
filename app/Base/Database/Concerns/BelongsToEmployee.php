<?php

namespace App\Base\Database\Concerns;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToEmployee
{
    protected const EMPLOYEE_FILLABLE = ['employee_id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
