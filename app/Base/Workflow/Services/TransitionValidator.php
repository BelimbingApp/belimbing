<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\Contracts\TransitionGuard;
use App\Base\Workflow\DTO\GuardResult;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates whether a transition is allowed.
 *
 * Evaluates three checks in sequence:
 * 1. Is the transition active?
 * 2. Does the actor have the required AuthZ capability?
 * 3. Does the guard class (if any) allow the transition for this instance?
 */
class TransitionValidator
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly Container $container,
    ) {}

    /**
     * Validate whether a transition can be triggered.
     *
     * @param  StatusTransition  $transition  The transition being attempted
     * @param  Actor  $actor  The actor triggering the transition
     * @param  Model|null  $model  The workflow participant (required if guard_class is set)
     */
    public function validate(StatusTransition $transition, Actor $actor, ?Model $model = null): GuardResult
    {
        if (! $transition->is_active) {
            return GuardResult::deny('Transition is inactive.');
        }

        if ($transition->capability !== null) {
            $decision = $this->authorizationService->can($actor, $transition->capability);

            if (! $decision->allowed) {
                return GuardResult::deny("Missing capability: {$transition->capability}");
            }
        }

        if ($transition->guard_class !== null) {
            if ($model === null) {
                return GuardResult::deny('Guard requires a model instance.');
            }

            /** @var TransitionGuard $guard */
            $guard = $this->container->make($transition->guard_class);

            return $guard->evaluate($model, $transition, $actor);
        }

        return GuardResult::allow();
    }
}
