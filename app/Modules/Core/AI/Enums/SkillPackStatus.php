<?php
namespace App\Modules\Core\AI\Enums;

/**
 * Readiness state for a registered skill pack.
 *
 * Skill packs declare readiness checks. This enum captures the
 * aggregate outcome so the runtime can skip packs that are not
 * ready without failing the entire run.
 */
enum SkillPackStatus: string
{
    /** All readiness checks passed; pack is available for resolution. */
    case Ready = 'ready';

    /** One or more readiness checks failed; pack is temporarily unavailable. */
    case Degraded = 'degraded';

    /** Pack is registered but administratively disabled. */
    case Disabled = 'disabled';

    /**
     * Whether the pack can be resolved for use.
     */
    public function isAvailable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Degraded => 'Degraded',
            self::Disabled => 'Disabled',
        };
    }
}
