<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu\Services;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use Illuminate\Contracts\Auth\Authenticatable;

class DefaultMenuAccessChecker implements MenuAccessChecker
{
    public function __construct(
        private readonly MenuConditionRegistry $conditionRegistry,
    ) {}

    /**
     * Determine visibility when no authorization adapter is installed.
     *
     * Menu is not an authorization boundary; route/middleware guards still enforce access.
     * Permissioned items default to an explicit strategy to keep behavior intentional.
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

        return config('menu.permissioned_items_without_authorizer', 'allow') === 'allow';
    }
}
