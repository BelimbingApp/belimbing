<?php

namespace App\Base\Workflow\Process\Contracts;

use App\Base\Workflow\Process\ProcessDefinitionRegistry;

/**
 * Module seam for code-owned durable process definitions.
 *
 * A module tags its contributor with CONTAINER_TAG. Definitions are registered
 * after every provider has booted, so Base never knows which domains use it.
 */
interface ProcessDefinitionContributor
{
    public const CONTAINER_TAG = 'base.workflow.process-definition-contributor';

    public function contribute(ProcessDefinitionRegistry $definitions): void;
}
