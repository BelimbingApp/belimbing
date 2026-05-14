<?php

namespace App\Base\Database\Concerns;

trait HasActiveInactiveStatus
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';
}
