<?php

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Durable, at-least-once delivery record for a committed workflow transition.
 *
 * @property int $id
 * @property string $event_key
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property Carbon $available_at
 * @property string|null $lease_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $delivered_at
 * @property string|null $last_error
 */
class TransitionOutboxMessage extends Model
{
    protected $table = 'base_workflow_transition_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
