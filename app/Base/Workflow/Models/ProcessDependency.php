<?php

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Directed dependency edge between two persisted process work items.
 *
 * @property int $id
 * @property int $work_item_id
 * @property int $depends_on_work_item_id
 * @property list<string> $acceptable_outcomes
 */
class ProcessDependency extends Model
{
    protected $table = 'base_workflow_process_dependencies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['acceptable_outcomes' => 'array'];
    }

    /** @return BelongsTo<ProcessWorkItem, $this> */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(ProcessWorkItem::class, 'depends_on_work_item_id');
    }
}
