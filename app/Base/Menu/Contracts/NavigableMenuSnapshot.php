<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu\Contracts;

use App\Base\Menu\MenuItem;
use App\Base\Menu\Services\VisibleNavMenuItemsFlat;
use Illuminate\Support\Collection;

/**
 * Authz-filtered navigable menu snapshot for the given user.
 *
 * The application binds this contract to {@see VisibleNavMenuItemsFlat}.
 */
interface NavigableMenuSnapshot
{
    /**
     * @return array{
     *     filtered: Collection<int, MenuItem>,
     *     flat: array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>
     * }
     */
    public function snapshotForUser(mixed $user): array;
}
