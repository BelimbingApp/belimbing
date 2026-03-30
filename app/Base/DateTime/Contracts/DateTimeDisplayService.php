<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\DateTime\Contracts;

use App\Base\DateTime\Enums\TimezoneMode;

interface DateTimeDisplayService
{
    /**
     * Format a value as a full datetime string.
     *
     * Returns an em-dash for null input. In LOCAL mode the value is returned
     * as a UTC ISO-8601 string so the Blade component can let the browser
     * convert it. In COMPANY/UTC modes the value is converted to the
     * resolved timezone and formatted as 'Y-m-d H:i'.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     */
    public function formatDateTime(\DateTimeInterface|string|null $value): string;

    /**
     * Format a value as a date-only string.
     *
     * Same null/LOCAL handling as {@see formatDateTime()}.
     * COMPANY/UTC modes format as 'Y-m-d'.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     */
    public function formatDate(\DateTimeInterface|string|null $value): string;

    /**
     * Format a value as a time-only string.
     *
     * Same null/LOCAL handling as {@see formatDateTime()}.
     * COMPANY/UTC modes format as 'H:i'.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     */
    public function formatTime(\DateTimeInterface|string|null $value): string;

    /**
     * Resolve the timezone display mode for the current authenticated user.
     *
     * Uses the settings cascade (employee → company → global) with key
     * 'ui.timezone.mode'. Falls back to COMPANY when no user is
     * authenticated or no override exists.
     */
    public function currentMode(): TimezoneMode;

    /**
     * Resolve the IANA timezone string for the current mode.
     *
     * COMPANY mode reads 'ui.timezone.default' from the company scope,
     * falling back to 'UTC'. UTC mode always returns 'UTC'. LOCAL mode
     * returns 'UTC' (the browser handles conversion).
     */
    public function currentTimezone(): string;

    /**
     * Convenience check: whether the current mode is LOCAL.
     */
    public function isLocalMode(): bool;
}
