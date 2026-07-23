<?php

namespace App\Base\Database\Enums;

/**
 * How the recorded first_key/last_key boundaries relate to the rows touched.
 * A hint is never proof of contiguity; effect counts remain authoritative.
 */
enum DataOperationRangeKind: string
{
    case Contiguous = 'contiguous';
    case MinMaxHint = 'min_max_hint';
    case NotApplicable = 'not_applicable';
}
