<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\DateTime\Services;

use App\Base\DateTime\Contracts\DateTimeDisplayService as DateTimeDisplayServiceContract;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Carbon\Carbon;

class DateTimeDisplayService implements DateTimeDisplayServiceContract
{
    private const FORMAT_DATETIME = 'datetime';

    private const FORMAT_DATE = 'date';

    private const FORMAT_TIME = 'time';

    private ?TimezoneMode $resolvedMode = null;

    private ?string $resolvedTimezone = null;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function formatDateTime(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, self::FORMAT_DATETIME);
    }

    /**
     * {@inheritdoc}
     */
    public function formatDate(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, self::FORMAT_DATE);
    }

    /**
     * {@inheritdoc}
     */
    public function formatTime(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, self::FORMAT_TIME);
    }

    /**
     * {@inheritdoc}
     */
    public function currentMode(): TimezoneMode
    {
        if ($this->resolvedMode !== null) {
            return $this->resolvedMode;
        }

        $user = auth()->user();

        if (! $user) {
            return $this->resolvedMode = TimezoneMode::COMPANY;
        }

        $scope = $user->employee_id
            ? Scope::employee($user->employee_id, $user->company_id ?? 0)
            : ($user->company_id ? Scope::company($user->company_id) : null);

        $raw = $this->settings->get('ui.timezone.mode', TimezoneMode::COMPANY->value, $scope);

        return $this->resolvedMode = TimezoneMode::tryFrom($raw) ?? TimezoneMode::COMPANY;
    }

    /**
     * {@inheritdoc}
     */
    public function currentTimezone(): string
    {
        if ($this->resolvedTimezone !== null) {
            return $this->resolvedTimezone;
        }

        return $this->resolvedTimezone = match ($this->currentMode()) {
            TimezoneMode::COMPANY => $this->resolveCompanyTimezone(),
            TimezoneMode::UTC,
            TimezoneMode::LOCAL => 'UTC',
        };
    }

    /**
     * {@inheritdoc}
     */
    public function isLocalMode(): bool
    {
        return $this->currentMode() === TimezoneMode::LOCAL;
    }

    /**
     * Resolve the company-level default timezone.
     *
     * Reads 'ui.timezone.default' from the company scope of the
     * authenticated user, falling back to 'UTC'.
     */
    private function resolveCompanyTimezone(): string
    {
        $user = auth()->user();

        if (! $user || ! $user->company_id) {
            return 'UTC';
        }

        return $this->settings->get(
            'ui.timezone.default',
            'UTC',
            Scope::company($user->company_id),
        );
    }

    /**
     * Shared formatting logic for all three format methods.
     *
     * Company mode uses locale-aware formatting via Carbon isoFormat (CLDR).
     * UTC/Stored mode uses a fixed ISO-like pattern (Y-m-d H:i) — raw database representation.
     * Local mode emits a UTC ISO-8601 string for browser-side formatting.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     * @param  string  $type  One of FORMAT_DATETIME, FORMAT_DATE, FORMAT_TIME
     */
    private function format(\DateTimeInterface|string|null $value, string $type): string
    {
        if ($value === null) {
            return '—';
        }

        $carbon = $value instanceof \DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);

        if ($this->isLocalMode()) {
            return $carbon->utc()->toIso8601String();
        }

        $carbon = $carbon->setTimezone($this->currentTimezone());

        if ($this->currentMode() === TimezoneMode::UTC) {
            return $carbon->format($this->storedFormat($type));
        }

        return $carbon->locale(app()->getLocale())->isoFormat($this->localeFormat($type));
    }

    /**
     * Fixed format patterns for Stored/UTC mode — raw database representation.
     */
    private function storedFormat(string $type): string
    {
        return match ($type) {
            self::FORMAT_DATE => 'Y-m-d',
            self::FORMAT_TIME => 'H:i',
            default => 'Y-m-d H:i',
        };
    }

    /**
     * CLDR isoFormat tokens for locale-aware rendering.
     *
     * L = locale short date (e.g. 15/06/2026 for ms, 06/15/2026 for en).
     * LT = locale short time (e.g. 08.00 for ms, 8:00 AM for en).
     */
    private function localeFormat(string $type): string
    {
        return match ($type) {
            self::FORMAT_DATE => 'L',
            self::FORMAT_TIME => 'LT',
            default => 'L LT',
        };
    }
}
