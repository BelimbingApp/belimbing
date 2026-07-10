<?php

namespace App\Base\Schedule\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use Illuminate\Console\Scheduling\Event;

final class ScheduledTaskExecutionException extends BlbInvariantViolationException
{
    public static function notRegistered(string $key): self
    {
        return new self("Scheduled task [{$key}] is not registered.");
    }

    public static function failed(Event $event, string $name): self
    {
        return new self("Scheduled task [{$name}] failed with exit code [{$event->exitCode}].");
    }
}
