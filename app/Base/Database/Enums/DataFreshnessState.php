<?php

namespace App\Base\Database\Enums;

/**
 * Whether a Local table has changed since its last acknowledged push. SQLite
 * and any unproven driver always report Unknown — never Clean.
 */
enum DataFreshnessState: string
{
    case Clean = 'clean';
    case Changed = 'changed';
    case Unknown = 'unknown';
}
