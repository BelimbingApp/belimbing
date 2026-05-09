<?php
namespace App\Modules\Core\AI\Enums;

enum LaraTaskType: string
{
    case Simple = 'simple';
    case Agentic = 'agentic';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple task',
            self::Agentic => 'Agentic task',
        };
    }
}
