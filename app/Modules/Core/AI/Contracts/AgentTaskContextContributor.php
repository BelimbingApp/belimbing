<?php

namespace App\Modules\Core\AI\Contracts;

use App\Modules\Core\AI\DTO\PromptSection;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes an operational prompt section for a domain entity attached to a
 * delegated agent task.
 *
 * The module that owns the entity implements this contract and tags the
 * binding with CONTAINER_TAG; AgentTaskPromptFactory consumes all tagged
 * contributors. This keeps Core/AI free of domain entity imports.
 */
interface AgentTaskContextContributor
{
    public const CONTAINER_TAG = 'blb.ai.agent-task-context';

    public function supports(Model $entity): bool;

    public function section(Model $entity): PromptSection;
}
