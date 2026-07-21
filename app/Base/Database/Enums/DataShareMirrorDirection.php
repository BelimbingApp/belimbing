<?php

namespace App\Base\Database\Enums;

use App\Base\Database\Exceptions\DataShareMirrorException;

enum DataShareMirrorDirection: string
{
    case Push = 'push';
    case Pull = 'pull';

    public static function parse(string $value): self
    {
        return self::tryFrom(mb_strtolower(trim($value)))
            ?? throw DataShareMirrorException::invalidDirection();
    }

    public function sourceConnection(): string
    {
        return match ($this) {
            self::Push => 'local',
            self::Pull => 'mirror',
        };
    }

    public function targetConnection(): string
    {
        return match ($this) {
            self::Push => 'mirror',
            self::Pull => 'local',
        };
    }
}
