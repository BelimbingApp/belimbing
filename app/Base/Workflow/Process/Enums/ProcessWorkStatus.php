<?php

namespace App\Base\Workflow\Process\Enums;

enum ProcessWorkStatus: string
{
    case PENDING = 'pending';
    case AVAILABLE = 'available';
    case LEASED = 'leased';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case WAIVED = 'waived';
    case BLOCKED = 'blocked';

    public function terminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::WAIVED, self::BLOCKED => true,
            default => false,
        };
    }
}
