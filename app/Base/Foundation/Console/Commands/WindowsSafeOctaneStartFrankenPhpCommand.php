<?php

namespace App\Base\Foundation\Console\Commands;

use App\Base\Foundation\Console\Commands\Concerns\SkipsSignalsWithoutPcntl;
use Laravel\Octane\Commands\StartFrankenPhpCommand;

class WindowsSafeOctaneStartFrankenPhpCommand extends StartFrankenPhpCommand
{
    use SkipsSignalsWithoutPcntl;
}
