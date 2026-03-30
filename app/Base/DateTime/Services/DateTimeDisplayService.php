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
        return $this->format($value, 'Y-m-d H:i');
    }

    /**
     * {@inheritdoc}
     */
    public function formatDate(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, 'Y-m-d');
    }

    /**
     * {@inheritdoc}
     */
    public function formatTime(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, 'H:i');
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
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     * @param  string  $format  PHP date format string
     */
    private function format(\DateTimeInterface|string|null $value, string $format): string
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

        return $carbon->setTimezone($this->currentTimezone())->format($format);
    }
}
