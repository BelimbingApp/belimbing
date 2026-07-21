<?php

namespace App\Base\Database\Enums;

enum DataShareMirrorAction: string
{
    case Create = 'create';
    case Replace = 'replace';
    case Delete = 'delete';
    case Blocked = 'blocked';
}
