<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Telemetry Event — normalized operational event for the AI control plane.
 *
 * Records structured events that tie together runs, sessions, dispatches,
 * and subsystem actions through shared identifiers. Supports correlation,
 * filtering, and incident review.
 *
 * @property string $id
 * @property TelemetryEventType $event_type
 * @property string|null $run_id
 * @property string|null $session_id
 * @property string|null $dispatch_id
 * @property int|null $employee_id
 * @property ControlPlaneTarget|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $employee
 */
class TelemetryEvent extends Model
{
    /**
     * Prefix for telemetry event IDs.
     */
    public const ID_PREFIX = 'te_';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_telemetry_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'event_type',
        'run_id',
        'session_id',
        'dispatch_id',
        'employee_id',
        'target_type',
        'target_id',
        'payload',
        'occurred_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => TelemetryEventType::class,
            'target_type' => ControlPlaneTarget::class,
            'payload' => 'json',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Get the agent associated with this event.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
