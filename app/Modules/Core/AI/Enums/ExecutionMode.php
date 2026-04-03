<?php

namespace App\Modules\Core\AI\Enums;

/**
 * Execution mode for AI runs — determines timeout tier and delivery strategy.
 */
enum ExecutionMode: string
{
    case Interactive = 'interactive';
    case HeavyForeground = 'heavy_foreground';
    case Background = 'background';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Interactive => 'Interactive',
            self::HeavyForeground => 'Heavy Foreground',
            self::Background => 'Background',
        };
    }

    /**
     * Whether this mode runs synchronously in the request cycle.
     */
    public function isForeground(): bool
    {
        return $this !== self::Background;
    }
}
