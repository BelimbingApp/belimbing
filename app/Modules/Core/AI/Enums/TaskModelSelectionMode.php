<?php
namespace App\Modules\Core\AI\Enums;

enum TaskModelSelectionMode: string
{
    case Recommended = 'recommended';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
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
