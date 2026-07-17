<?php

namespace App\Base\Workflow\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional richer guard contract for decisions that depend on transition input.
 *
 * Existing guards keep the smaller TransitionGuard interface. A guard opts into
 * this contract only when it needs context such as a proposed assignee.
 */
interface ContextualTransitionGuard extends TransitionGuard
{
    public function evaluateWithContext(
        Model $model,
        StatusTransition $transition,
        Actor $actor,
        TransitionContext $context,
    ): GuardResult;
}
