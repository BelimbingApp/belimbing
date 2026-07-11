<?php

namespace App\Base\Schedule\Livewire\Concerns;

use App\Base\Schedule\DTO\ScheduleTask;
use Cron\CronExpression;

trait DescribesCronSchedules
{
    /**
     * @param  list<ScheduleTask>  $tasks
     * @return array<string, string|null>
     */
    private function cronDescriptions(array $tasks): array
    {
        $descriptions = [];

        foreach ($tasks as $task) {
            $descriptions[$task->source.'|'.$task->key] = $this->describeCron($task->cron);
        }

        return $descriptions;
    }

    private function describeCron(string $cron): ?string
    {
        $cron = trim($cron);
        $fields = preg_split('/\s+/', $cron) ?: [];

        if (count($fields) !== 5) {
            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        return $this->describeEveryMinute($cron)
            ?? $this->describeMinuteInterval($minute, $hour, $dayOfMonth, $month, $dayOfWeek)
            ?? $this->describeHourInterval($minute, $hour, $dayOfMonth, $month, $dayOfWeek)
            ?? $this->describeHourlyAtMinute($minute, $hour, $dayOfMonth, $month, $dayOfWeek)
            ?? $this->describeCalendarSchedule($minute, $hour, $dayOfMonth, $month, $dayOfWeek)
            ?? (CronExpression::isValidExpression($cron) ? __('Custom cron schedule') : __('Invalid cron expression'));
    }

    private function describeEveryMinute(string $cron): ?string
    {
        if ($cron === '* * * * *') {
            return __('Every minute');
        }

        return null;
    }

    private function describeMinuteInterval(string $minute, string $hour, string $dayOfMonth, string $month, string $dayOfWeek): ?string
    {
        if (preg_match('/^\*\/([1-9]\d*)$/', $minute, $match) === 1
            && $hour === '*'
            && $dayOfMonth === '*'
            && $month === '*'
            && $dayOfWeek === '*') {
            $count = (int) $match[1];

            return trans_choice('Every :count minute|Every :count minutes', $count, ['count' => $count]);
        }

        return null;
    }

    private function describeHourInterval(string $minute, string $hour, string $dayOfMonth, string $month, string $dayOfWeek): ?string
    {
        if ($minute === '0'
            && preg_match('/^\*\/([1-9]\d*)$/', $hour, $match) === 1
            && $dayOfMonth === '*'
            && $month === '*'
            && $dayOfWeek === '*') {
            $count = (int) $match[1];

            return trans_choice('Every :count hour|Every :count hours', $count, ['count' => $count]);
        }

        return null;
    }

    private function describeHourlyAtMinute(string $minute, string $hour, string $dayOfMonth, string $month, string $dayOfWeek): ?string
    {
        if ($this->isNumericCronField($minute) && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return __('Hourly at :minute minutes past the hour', ['minute' => str_pad($minute, 2, '0', STR_PAD_LEFT)]);
        }

        return null;
    }

    private function describeCalendarSchedule(string $minute, string $hour, string $dayOfMonth, string $month, string $dayOfWeek): ?string
    {
        $description = null;

        if ($this->isNumericCronField($minute) && $this->isNumericCronField($hour) && $month === '*') {
            $time = $this->cronTimeLabel($hour, $minute);
            $weekdayName = $this->weekdayName($dayOfWeek);

            if ($dayOfMonth === '*' && $dayOfWeek === '*') {
                $description = __('Daily at :time', ['time' => $time]);
            } elseif ($dayOfMonth === '*' && $dayOfWeek === '1-5') {
                $description = __('Weekdays at :time', ['time' => $time]);
            } elseif ($dayOfMonth === '*' && $weekdayName !== null) {
                $description = __('Weekly on :day at :time', ['day' => $weekdayName, 'time' => $time]);
            } elseif ($this->isNumericCronField($dayOfMonth) && $dayOfWeek === '*') {
                $description = __('Monthly on day :day at :time', ['day' => (int) $dayOfMonth, 'time' => $time]);
            }
        }

        return $description;
    }

    private function isNumericCronField(string $field): bool
    {
        return ctype_digit($field);
    }

    private function cronTimeLabel(string $hour, string $minute): string
    {
        return str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);
    }

    private function weekdayName(string $dayOfWeek): ?string
    {
        return match ($dayOfWeek) {
            '0', '7' => __('Sunday'),
            '1' => __('Monday'),
            '2' => __('Tuesday'),
            '3' => __('Wednesday'),
            '4' => __('Thursday'),
            '5' => __('Friday'),
            '6' => __('Saturday'),
            default => null,
        };
    }
}
