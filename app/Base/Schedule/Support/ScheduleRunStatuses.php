<?php

namespace App\Base\Schedule\Support;

final class ScheduleRunStatuses
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const NEVER = 'never';

    /**
     * @return list<string>
     */
    public static function recorded(): array
    {
        return [
            self::RUNNING,
            self::SUCCEEDED,
            self::FAILED,
            self::SKIPPED,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::SUCCEEDED => __('Succeeded'),
            self::FAILED => __('Failed'),
            self::SKIPPED => __('Skipped'),
            self::RUNNING => __('Running'),
            default => __('Never'),
        };
    }

    public static function variant(string $status): string
    {
        return match ($status) {
            self::SUCCEEDED => 'success',
            self::FAILED => 'danger',
            self::SKIPPED => 'warning',
            self::RUNNING => 'info',
            default => 'default',
        };
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::SUCCEEDED, self::FAILED, self::SKIPPED], true);
    }
}
