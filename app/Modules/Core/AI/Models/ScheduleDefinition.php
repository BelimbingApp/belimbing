<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Schedule definition — durable record for proactive automation schedules.
 *
 * Stores cron-based schedule intent definitions with policy, scope,
 * and target behavior. The SchedulePlanner uses this model to compute
 * due work and dispatch execution through the operations ledger.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $employee_id
 * @property int|null $created_by_user_id
 * @property string $description
 * @property string $execution_payload
 * @property string $cron_expression
 * @property string $timezone
 * @property bool $is_enabled
 * @property string $concurrency_policy
 * @property Carbon|null $last_fired_at
 * @property Carbon|null $next_due_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Employee|null $employee
 * @property-read User|null $createdByUser
 */
class ScheduleDefinition extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_schedule_definitions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'created_by_user_id',
        'description',
        'execution_payload',
        'cron_expression',
        'timezone',
        'is_enabled',
        'concurrency_policy',
        'last_fired_at',
        'next_due_at',
        'meta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_fired_at' => 'datetime',
            'next_due_at' => 'datetime',
            'meta' => 'json',
        ];
    }

    /**
     * Get the company this schedule belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the target agent employee.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user who created this schedule.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Compute the next due time based on the cron expression and timezone.
     *
     * @param  Carbon|null  $relativeTo  Reference time (defaults to now)
     */
    public function computeNextDue(?Carbon $relativeTo = null): Carbon
    {
        $cron = new CronExpression($this->cron_expression);
        $referenceTime = ($relativeTo ?? now())->timezone($this->timezone);

        $nextRun = Carbon::instance($cron->getNextRunDate($referenceTime->toDateTime()));

        return $nextRun->utc();
    }

    /**
     * Refresh the next_due_at field based on current cron expression.
     */
    public function refreshNextDue(): void
    {
        $this->update(['next_due_at' => $this->computeNextDue()]);
    }

    /**
     * Record that this schedule was fired and advance next_due_at.
     */
    public function recordFired(): void
    {
        $now = now();

        $this->update([
            'last_fired_at' => $now,
            'next_due_at' => $this->computeNextDue($now),
        ]);
    }

    /**
     * Determine if the schedule has a running operation and skip policy applies.
     */
    public function shouldSkipForConcurrency(): bool
    {
        if ($this->concurrency_policy !== 'skip') {
            return false;
        }

        return OperationDispatch::query()
            ->where('operation_type', 'scheduled_task')
            ->whereJsonContains('meta->schedule_id', $this->id)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
