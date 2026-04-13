<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

enum TaskModelSelectionMode: string
{
    case Primary = 'primary';
    case Recommended = 'recommended';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Use Lara primary',
            self::Recommended => 'Recommended',
            self::Manual => 'Choose manually',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
