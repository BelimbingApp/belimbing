<?php
namespace App\Base\Menu\Contracts;

use App\Base\Menu\MenuItem;
use Illuminate\Contracts\Auth\Authenticatable;

interface MenuAccessChecker
{
    /**
     * Determine whether a user can see a menu item.
     *
     * @param  MenuItem  $item  Menu item definition, including optional permission key
     * @param  Authenticatable  $user  Current authenticated user
     */
    public function canView(MenuItem $item, Authenticatable $user): bool;
}
