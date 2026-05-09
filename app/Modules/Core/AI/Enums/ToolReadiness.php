<?php
namespace App\Modules\Core\AI\Enums;

/**
 * Readiness state for a Agent tool.
 *
 * Answers "can this tool be used?" — separate from health (is it behaving well?).
 */
enum ToolReadiness: string
{
    case UNAVAILABLE = 'unavailable';
    case UNAUTHORIZED = 'unauthorized';
    case UNCONFIGURED = 'unconfigured';
    case NEEDS_ATTENTION = 'needs_attention';
    case READY = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::UNAVAILABLE => __('Unavailable'),
            self::UNAUTHORIZED => __('Unauthorized'),
            self::UNCONFIGURED => __('Unconfigured'),
            self::NEEDS_ATTENTION => __('Needs Attention'),
            self::READY => __('Ready'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::UNAVAILABLE => 'default',
            self::UNAUTHORIZED => 'danger',
            self::UNCONFIGURED => 'warning',
            self::NEEDS_ATTENTION => 'warning',
            self::READY => 'success',
        };
    }
}
