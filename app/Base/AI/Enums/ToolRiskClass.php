<?php
namespace App\Base\AI\Enums;

/**
 * Risk classification for AI tools.
 *
 * Helps users understand the potential impact of a tool at a glance.
 */
enum ToolRiskClass: string
{
    case READ_ONLY = 'read_only';
    case INTERNAL = 'internal';
    case EXTERNAL_IO = 'external_io';
    case BROWSER = 'browser';
    case MESSAGING = 'messaging';
    case HIGH_IMPACT = 'high_impact';

    public function label(): string
    {
        return match ($this) {
            self::READ_ONLY => __('Read-only'),
            self::INTERNAL => __('Internal'),
            self::EXTERNAL_IO => __('External I/O'),
            self::BROWSER => __('Browser'),
            self::MESSAGING => __('Messaging'),
            self::HIGH_IMPACT => __('High-impact'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::READ_ONLY => 'success',
            self::INTERNAL => 'default',
            self::EXTERNAL_IO => 'warning',
            self::BROWSER => 'warning',
            self::MESSAGING => 'warning',
            self::HIGH_IMPACT => 'danger',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::READ_ONLY => 1,
            self::INTERNAL => 2,
            self::EXTERNAL_IO => 3,
            self::BROWSER => 4,
            self::MESSAGING => 5,
            self::HIGH_IMPACT => 6,
        };
    }
}
