<?php
namespace App\Modules\Core\AI\Enums;

/**
 * Health state for a Agent tool.
 *
 * Answers "is this tool behaving well right now?" — separate from readiness.
 */
enum ToolHealthState: string
{
    case UNKNOWN = 'unknown';
    case HEALTHY = 'healthy';
    case DEGRADED = 'degraded';
    case FAILING = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::UNKNOWN => __('Unknown'),
            self::HEALTHY => __('Healthy'),
            self::DEGRADED => __('Degraded'),
            self::FAILING => __('Failing'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::UNKNOWN => 'default',
            self::HEALTHY => 'success',
            self::DEGRADED => 'warning',
            self::FAILING => 'danger',
        };
    }
}
