<?php

namespace Tests\Unit\Base\Workflow\Fixtures;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent subject so Larastan analyses HasWorkflowStatus.
 */
class WorkflowStatusSubject extends Model
{
    use HasWorkflowStatus;

    protected $table = 'workflow_status_subjects_fixture';

    public function flow(): string
    {
        return 'fixture_flow';
    }
}
