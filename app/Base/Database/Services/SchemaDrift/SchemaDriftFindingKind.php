<?php

namespace App\Base\Database\Services\SchemaDrift;

enum SchemaDriftFindingKind: string
{
    case MISSING_TABLE = 'missing_table';
    case MISSING_COLUMN = 'missing_column';
    case UNEXPECTED_COLUMN = 'unexpected_column';
    case MISSING_INDEX = 'missing_index';
    case UNEXPECTED_UNIQUE_INDEX = 'unexpected_unique_index';
}
