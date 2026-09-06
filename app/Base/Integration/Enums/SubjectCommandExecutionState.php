<?php

namespace App\Base\Integration\Enums;

/** Whether the boundary invoked the command transport. */
enum SubjectCommandExecutionState: string
{
    case Executed = 'executed';
    case RefusedBeforeDispatch = 'refused_before_dispatch';
}
