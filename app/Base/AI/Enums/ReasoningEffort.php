<?php

namespace App\Base\AI\Enums;

enum ReasoningEffort: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::XHigh => 'X-High',
            default => ucfirst($this->value),
        };
    }
}
