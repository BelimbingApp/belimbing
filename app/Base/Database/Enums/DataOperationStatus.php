<?php

namespace App\Base\Database\Enums;

/**
 * Terminal meanings are strict and never guessed. See "Preserve honest
 * transaction boundaries" in the plan.
 */
enum DataOperationStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
