<?php

namespace App\Base\DateTime\Contracts;

use App\Base\DateTime\Enums\TimezoneMode;

interface DateTimeDisplayService
{
    /**
     * Format a value as a full datetime string.
     *
     * Returns an em-dash for null input. In LOCAL mode the value is returned
     * as a UTC ISO-8601 string so the Blade component can let the browser
     * convert it. In COMPANY mode the value is formatted using the resolved
     * display locale (ICU IntlDateFormatter with the resolved locale). In UTC/Stored mode the
     * value uses a fixed 'Y-m-d H:i:s' pattern — the raw database format.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     */
    public function formatDateTime(\DateTimeInterface|string|null $value): string;

    /**
     * Format a value as a date-only string.
     *
     * Same null/LOCAL handling as {@see formatDateTime()}.
     * COMPANY mode uses ICU IntlDateFormatter (date-only). UTC/Stored mode uses 'Y-m-d'.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     */
    public function formatDate(\DateTimeInterface|string|null $value): string;

    /**
     * Format a value as a time-only string.
     *
     * Same null/LOCAL handling as {@see formatDateTime()}.
     * COMPANY mode uses ICU IntlDateFormatter (time-only). UTC/Stored mode uses 'H:i:s'.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     */
    public function formatTime(\DateTimeInterface|string|null $value): string;

    /**
     * Resolve the timezone display mode for the current authenticated user.
     *
     * Reads the authenticated account's user-scoped 'ui.timezone.mode'.
     * Falls back to COMPANY when no user is authenticated or no override
     * exists.
     */
    public function currentMode(): TimezoneMode;

    /**
     * Resolve the IANA timezone string for the current mode.
     *
     * COMPANY mode reads company-scoped 'localization.timezone', falling back
     * to its declared 'UTC' default. UTC mode always returns 'UTC'. LOCAL mode
     * returns 'UTC' for browser-side conversion.
     */
    public function currentTimezone(): string;

    /**
     * Resolve the configured company timezone independent of the active mode.
     *
     * Reads company-scoped 'localization.timezone', falling back to its
     * declared 'UTC' default.
     */
    public function currentCompanyTimezone(): string;

    /**
     * Convenience check: whether the current mode is LOCAL.
     */
    public function isLocalMode(): bool;

    /**
     * Check whether the company timezone has been explicitly configured.
     *
     * Returns true when 'localization.timezone' exists in company scope,
     * false when the timezone comes from its code default.
     */
    public function isCompanyTimezoneExplicit(): bool;
}
