<?php

namespace App\Base\Database\Enums;

/**
 * What the hydration guard does when a unit of work crosses its limit.
 */
enum HydrationGuardMode: string
{
    /** Throw HydrationLimitExceededException so the offending code path fails. */
    case Throw = 'throw';

    /** Log one structured warning per unit of work and let it continue. */
    case Log = 'log';
}
