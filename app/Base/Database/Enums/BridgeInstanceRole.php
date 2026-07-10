<?php

namespace App\Base\Database\Enums;

enum BridgeInstanceRole: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    public function rank(): int
    {
        return match ($this) {
            self::Development => 1,
            self::Staging => 2,
            self::Production => 3,
        };
    }
}
