<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Scheduling;

use App\Modules\Core\AI\Models\ScheduleDefinition;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD operations over schedule definitions.
 *
 * Provides the data access layer for schedule definitions, including
 * creation, update, deletion, and querying. All mutations automatically
 * recompute next_due_at to keep the planner's index current.
 *
 * Tools call this service — they never mutate ScheduleDefinition directly.
 */
class ScheduleDefinitionService
{
    /**
     * List schedules for a company, optionally filtered by enabled state.
     *
     * @param  int  $companyId  Company ID
     * @param  bool|null  $enabledOnly  Filter by enabled state (null = all)
     * @param  int  $limit  Maximum number of results
     * @return Collection<int, ScheduleDefinition>
     */
    public function list(int $companyId, ?bool $enabledOnly = null, int $limit = 50): Collection
    {
        $query = ScheduleDefinition::query()
            ->where('company_id', $companyId)
            ->orderBy('next_due_at');

        if ($enabledOnly !== null) {
            $query->where('is_enabled', $enabledOnly);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Create a new schedule definition.
     *
     * @param  int  $companyId  Owning company ID
     * @param  array{description: string, execution_payload: string, cron_expression: string, employee_id?: int|null, timezone?: string, is_enabled?: bool, concurrency_policy?: string, created_by_user_id?: int|null, meta?: array<string, mixed>}  $data  Schedule data
     *
     * @throws \InvalidArgumentException If the cron expression is invalid
     */
    public function create(int $companyId, array $data): ScheduleDefinition
    {
        $this->validateCronExpression($data['cron_expression']);

        $timezone = $data['timezone'] ?? 'UTC';

        $schedule = ScheduleDefinition::query()->create([
            'company_id' => $companyId,
            'employee_id' => $data['employee_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
            'description' => $data['description'],
            'execution_payload' => $data['execution_payload'],
            'cron_expression' => $data['cron_expression'],
            'timezone' => $timezone,
            'is_enabled' => $data['is_enabled'] ?? true,
            'concurrency_policy' => $data['concurrency_policy'] ?? 'skip',
            'meta' => $data['meta'] ?? null,
        ]);

        // Compute initial next_due_at
        $schedule->refreshNextDue();

        return $schedule->refresh();
    }

    /**
     * Update an existing schedule definition.
     *
     * @param  int  $scheduleId  Schedule definition ID
     * @param  int  $companyId  Company ID (for scoping/authorization)
     * @param  array<string, mixed>  $data  Fields to update
     *
     * @throws \InvalidArgumentException If the cron expression is invalid
     */
    public function update(int $scheduleId, int $companyId, array $data): ?ScheduleDefinition
    {
        $schedule = ScheduleDefinition::query()
            ->where('id', $scheduleId)
            ->where('company_id', $companyId)
            ->first();

        if ($schedule === null) {
            return null;
        }

        if (isset($data['cron_expression'])) {
            $this->validateCronExpression($data['cron_expression']);
        }

        $schedule->update($data);

        // Recompute next_due_at if cron or timezone changed
        if (isset($data['cron_expression']) || isset($data['timezone'])) {
            $schedule->refreshNextDue();
        }

        return $schedule->refresh();
    }

    /**
     * Remove a schedule definition.
     *
     * @param  int  $scheduleId  Schedule definition ID
     * @param  int  $companyId  Company ID (for scoping/authorization)
     * @return bool Whether the schedule was found and deleted
     */
    public function remove(int $scheduleId, int $companyId): bool
    {
        $schedule = ScheduleDefinition::query()
            ->where('id', $scheduleId)
            ->where('company_id', $companyId)
            ->first();

        if ($schedule === null) {
            return false;
        }

        $schedule->delete();

        return true;
    }

    /**
     * Retrieve a single schedule definition by ID and company.
     *
     * @param  int  $scheduleId  Schedule definition ID
     * @param  int  $companyId  Company ID (for scoping)
     */
    public function find(int $scheduleId, int $companyId): ?ScheduleDefinition
    {
        return ScheduleDefinition::query()
            ->where('id', $scheduleId)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * Validate a cron expression string.
     *
     * @throws \InvalidArgumentException If the expression is invalid
     */
    private function validateCronExpression(string $expression): void
    {
        if (! CronExpression::isValidExpression($expression)) {
            throw new \InvalidArgumentException(
                'Invalid cron expression: "'.$expression.'". '
                .'Expected standard 5-field format: "minute hour day month weekday".',
            );
        }
    }
}
