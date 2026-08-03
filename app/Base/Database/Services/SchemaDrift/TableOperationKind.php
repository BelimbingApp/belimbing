<?php

namespace App\Base\Database\Services\SchemaDrift;

enum TableOperationKind: string
{
    case CREATE_TABLE = 'create_table';
    case DROP_TABLE = 'drop_table';
    case RENAME_TABLE = 'rename_table';
    case ADD_COLUMN = 'add_column';
    case DROP_COLUMN = 'drop_column';
    case RENAME_COLUMN = 'rename_column';
    case ADD_INDEX = 'add_index';
    case DROP_INDEX = 'drop_index';
    case RENAME_INDEX = 'rename_index';
}
