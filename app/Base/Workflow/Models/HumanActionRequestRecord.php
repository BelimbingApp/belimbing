<?php

namespace App\Base\Workflow\Models;

use App\Base\Database\Contracts\GrowingTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Idempotent ledger row for an executed human action request.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $idempotency_key
 * @property string $intent_hash
 * @property string $action_key
 * @property string $subject_type
 * @property string $subject_id
 * @property int|null $process_run_id
 * @property int|null $work_item_id
 * @property string $actor_type
 * @property int $actor_id
 * @property array<string, mixed>|null $result
 * @property Carbon|null $completed_at
 */
class HumanActionRequestRecord extends Model implements GrowingTable
{
    protected $table = 'base_workflow_human_action_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'datetime'];
    }
}
