<?php

namespace App\Modules\Core\AI\Services\Scheduling;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Database\Exceptions\DevelopmentSanitizationException;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class AiScheduleDevelopmentSanitizer implements DevelopmentSanitizationContributor
{
    public function key(): string
    {
        return 'ai-schedules';
    }

    public function preview(): DevelopmentSanitizationResult
    {
        return $this->result($this->enabledQuery()->count());
    }

    public function apply(): DevelopmentSanitizationResult
    {
        $affected = $this->enabledQuery()->update([
            'is_enabled' => false,
            'run_requested_at' => null,
            'next_due_at' => null,
            'updated_at' => now(),
        ]);

        return $this->result($affected);
    }

    private function enabledQuery(): Builder
    {
        if (! Schema::hasTable('ai_schedule_definitions')) {
            throw DevelopmentSanitizationException::missingTable('ai_schedule_definitions');
        }

        return ScheduleDefinition::query()->where('is_enabled', true);
    }

    private function result(int $affected): DevelopmentSanitizationResult
    {
        return new DevelopmentSanitizationResult(
            key: $this->key(),
            label: __('AI schedules'),
            affected: $affected,
            detail: __('Disable restored agent and headless schedules and clear pending run requests.'),
        );
    }
}
