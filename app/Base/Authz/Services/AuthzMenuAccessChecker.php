<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Menu\Services\MenuConditionRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

class AuthzMenuAccessChecker implements MenuAccessChecker
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly MenuConditionRegistry $conditionRegistry,
    ) {}

    /**
     * Evaluate menu item permission through Authz policy pipeline.
     *
     * @param  MenuItem  $item  Menu item definition, including optional permission key
     * @param  Authenticatable  $user  Current authenticated user
     */
    public function canView(MenuItem $item, Authenticatable $user): bool
    {
        if (! $this->conditionRegistry->allows($item->condition, $user)) {
            return false;
        }

        if ($item->permission === null) {
            return true;
        }

        $actor = Actor::forUser($user);

        return $this->authorizationService->can($actor, $item->permission)->allowed;
    }
}
