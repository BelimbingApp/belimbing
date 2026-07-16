<?php

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Database-enforced reservation of one immutable process definition version.
 *
 * @property int $id
 * @property string $definition_key
 * @property int $definition_version
 * @property string $definition_fingerprint
 */
class ProcessDefinitionVersion extends Model
{
    protected $table = 'base_workflow_process_definition_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition_version' => 'integer'];
    }
}
