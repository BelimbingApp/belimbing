<?php

namespace App\Base\Workflow\Human\Contracts;

use App\Base\Workflow\Human\HumanActionRegistry;

interface HumanActionContributor
{
    public const CONTAINER_TAG = 'workflow.human-action-contributors';

    public function contribute(HumanActionRegistry $actions): void;
}
