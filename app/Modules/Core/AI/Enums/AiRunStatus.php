<?php
namespace App\Modules\Core\AI\Enums;

/**
 * Lifecycle states for universal AI run envelopes.
 */
enum AiRunStatus: string
{
    case Queued = 'queued';
    case Booting = 'booting';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    /**
     * Determine whether this status represents a terminal (complete) state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled, self::TimedOut => true,
            default => false,
        };
    }

    /**
     * Whether this status represents active or pending work.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Valid state transitions.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Booting, self::Failed, self::Cancelled, self::TimedOut],
            self::Booting => [self::Running, self::Failed, self::Cancelled, self::TimedOut],
            self::Running => [self::Succeeded, self::Failed, self::Cancelled, self::TimedOut],
            self::Succeeded, self::Failed, self::Cancelled, self::TimedOut => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Booting => 'Booting',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::TimedOut => 'Timed Out',
        };
    }

    /**
     * UI badge variant.
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued => 'default',
            self::Booting => 'info',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
            self::TimedOut => 'danger',
        };
    }
}
