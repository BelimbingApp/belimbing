<?php

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

class HumanActionRequestRecord extends Model
{
    protected $table = 'base_workflow_human_action_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'datetime'];
    }
}
