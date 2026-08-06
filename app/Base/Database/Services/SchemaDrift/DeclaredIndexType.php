<?php

namespace App\Base\Database\Services\SchemaDrift;

enum DeclaredIndexType: string
{
    case INDEX = 'index';
    case UNIQUE = 'unique';
    case PRIMARY = 'primary';
}
