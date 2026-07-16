<?php

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail for process orchestration decisions.
 *
 * @property int $id
 * @property int $process_run_id
 * @property int|null $work_item_id
 * @property int $sequence
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property string|null $idempotency_key
 * @property Carbon $occurred_at
 */
class ProcessEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'base_workflow_process_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
