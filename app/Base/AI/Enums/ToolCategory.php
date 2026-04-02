<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

/**
 * Categories for grouping AI tools in the catalog UI.
 */
enum ToolCategory: string
{
    case DATA = 'data';
    case WEB = 'web';
    case SYSTEM = 'system';
    case MEMORY = 'memory';
    case DELEGATION = 'delegation';
    case BROWSER = 'browser';
    case MESSAGING = 'messaging';
    case AUTOMATION = 'automation';
    case MEDIA = 'media';
    case CONTEXT = 'context';

    public function label(): string
    {
        return match ($this) {
            self::DATA => __('Data'),
            self::WEB => __('Web'),
            self::SYSTEM => __('System'),
            self::MEMORY => __('Memory'),
            self::DELEGATION => __('Delegation'),
            self::BROWSER => __('Browser'),
            self::MESSAGING => __('Messaging'),
            self::AUTOMATION => __('Automation'),
            self::MEDIA => __('Media'),
            self::CONTEXT => __('Context'),
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::CONTEXT => 0,
            self::DATA => 1,
            self::WEB => 2,
            self::MEMORY => 3,
            self::SYSTEM => 4,
            self::BROWSER => 5,
            self::DELEGATION => 6,
            self::MESSAGING => 7,
            self::AUTOMATION => 8,
            self::MEDIA => 9,
        };
    }
}
