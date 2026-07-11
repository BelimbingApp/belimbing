<?php

namespace App\Base\Foundation\Console\Commands;

use App\Base\Foundation\Console\Commands\Concerns\SkipsSignalsWithoutPcntl;
use Laravel\Octane\Commands\StartCommand;

class WindowsSafeOctaneStartCommand extends StartCommand
{
    use SkipsSignalsWithoutPcntl;
}
