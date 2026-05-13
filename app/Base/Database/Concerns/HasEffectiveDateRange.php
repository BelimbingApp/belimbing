<?php

namespace App\Base\Database\Concerns;

trait HasEffectiveDateRange
{
    protected const EFFECTIVE_DATE_RANGE_FILLABLE = [
        'effective_from',
        'effective_to',
    ];

    protected const EFFECTIVE_DATE_RANGE_CASTS = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];
}
