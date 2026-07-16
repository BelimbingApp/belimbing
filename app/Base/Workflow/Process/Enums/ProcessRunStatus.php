<?php

namespace App\Base\Workflow\Process\Enums;

enum ProcessRunStatus: string
{
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case BLOCKED = 'blocked';

    public function terminal(): bool
    {
        return ! in_array($this, [self::RUNNING, self::PAUSED], true);
    }
}
