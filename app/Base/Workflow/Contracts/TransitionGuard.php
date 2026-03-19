<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;

interface TransitionGuard
{
    /**
     * Evaluate whether a transition is allowed for this instance.
     *
     * @param  Model  $model  The workflow participant (e.g., LeaveApplication)
     * @param  StatusTransition  $transition  The transition being attempted
     * @param  Actor  $actor  The actor triggering the transition
     */
    public function evaluate(Model $model, StatusTransition $transition, Actor $actor): GuardResult;
}
