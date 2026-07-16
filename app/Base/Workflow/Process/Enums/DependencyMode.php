<?php

namespace App\Base\Workflow\Process\Enums;

enum DependencyMode: string
{
    case ALL = 'all';
    case ANY = 'any';
}
