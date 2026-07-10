<?php

namespace App\Base\Schedule\Services;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Database\Exceptions\DevelopmentSanitizationException;
use App\Base\Schedule\Models\ScheduleSuppression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

class FrameworkScheduleDevelopmentSanitizer implements DevelopmentSanitizationContributor
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ScheduleRunRecorder $recorder,
    ) {}

    public function key(): string
    {
        return 'framework-schedules';
    }

    public function preview(): DevelopmentSanitizationResult
    {
        $targets = $this->targets();
        $existing = $targets === []
            ? 0
            : ScheduleSuppression::query()
                ->where('source', 'scheduler')
                ->whereIn('key', array_keys($targets))
                ->count();

        return $this->result(count($targets) - $existing);
    }

    public function apply(): DevelopmentSanitizationResult
    {
        $affected = 0;

        foreach ($this->targets() as $key => $name) {
            $suppression = ScheduleSuppression::query()->firstOrCreate([
                'source' => 'scheduler',
                'key' => $key,
            ], [
                'name' => $name,
            ]);

            if ($suppression->wasRecentlyCreated) {
                $affected++;
            }
        }

        return $this->result($affected);
    }

    /** @return array<string, string> */
    private function targets(): array
    {
        if (! Schema::hasTable('base_schedule_suppressions')) {
            throw DevelopmentSanitizationException::missingTable('base_schedule_suppressions');
        }

        $targets = [];

        foreach ($this->schedule->events() as $event) {
            $targets[$this->recorder->key($event)] = $this->recorder->name($event);
        }

        return $targets;
    }

    private function result(int $affected): DevelopmentSanitizationResult
    {
        return new DevelopmentSanitizationResult(
            key: $this->key(),
            label: __('Framework schedules'),
            affected: $affected,
            detail: __('Pause every registered Laravel scheduler event until a developer explicitly reviews and resumes it.'),
        );
    }
}
