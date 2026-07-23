<?php

namespace App\Base\DateTime\Services;

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;

/**
 * Typed access to timezone policy and user display preferences.
 */
final readonly class TimezoneSettings
{
    public const string LOCALIZATION_TIMEZONE_KEY = 'localization.timezone';

    public const string MODE_KEY = 'ui.timezone.mode';

    public function __construct(
        private SettingsService $settings,
    ) {}

    public function modeForUser(int $userId): TimezoneMode
    {
        $value = $this->settings->get(
            self::MODE_KEY,
            scope: Scope::user($userId),
        );

        return is_string($value)
            ? TimezoneMode::tryFrom($value) ?? TimezoneMode::COMPANY
            : TimezoneMode::COMPANY;
    }

    public function setModeForUser(int $userId, TimezoneMode $mode): void
    {
        $this->settings->set(
            self::MODE_KEY,
            $mode->value,
            Scope::user($userId),
        );
    }

    public function companyTimezone(?int $companyId): string
    {
        $value = $this->settings->get(
            self::LOCALIZATION_TIMEZONE_KEY,
            scope: $companyId !== null ? Scope::company($companyId) : null,
        );

        return is_string($value) && $value !== '' ? $value : 'UTC';
    }

    public function explicitCompanyTimezone(int $companyId): ?string
    {
        $scope = Scope::company($companyId);

        if (! $this->settings->has(self::LOCALIZATION_TIMEZONE_KEY, $scope)) {
            return null;
        }

        $value = $this->settings->get(
            self::LOCALIZATION_TIMEZONE_KEY,
            scope: $scope,
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setCompanyTimezone(int $companyId, string $timezone): void
    {
        $this->settings->set(
            self::LOCALIZATION_TIMEZONE_KEY,
            $timezone,
            Scope::company($companyId),
        );
    }

    public function forgetCompanyTimezone(int $companyId): void
    {
        $this->settings->forget(
            self::LOCALIZATION_TIMEZONE_KEY,
            Scope::company($companyId),
        );
    }
}
