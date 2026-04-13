<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\DateTime\Controllers;

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Handles timezone display mode changes.
 *
 * Called from the top-bar Alpine dropdown via fetch().
 * Accepts a specific mode to set (company, local, or utc).
 */
class TimezoneController
{
    private const SETTINGS_KEY = 'ui.timezone.mode';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DateTimeDisplayService $dateTimeDisplay,
    ) {}

    /**
     * Set the timezone display mode for the authenticated user.
     *
     * Persists at the most specific available scope (employee or company).
     * Returns the new mode and resolved IANA timezone identifier.
     */
    public function set(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(array_column(TimezoneMode::cases(), 'value'))],
        ]);

        $user = $request->user();
        $scope = $this->resolveScope($user);
        $mode = TimezoneMode::from($validated['mode']);

        $this->settings->set(self::SETTINGS_KEY, $mode->value, $scope);

        $companyTimezone = $this->dateTimeDisplay->currentCompanyTimezone();

        return response()->json([
            'mode' => $mode->value,
            'label' => $mode->label(),
            'timezone' => $this->resolveTimezoneForMode($mode, $companyTimezone),
            'company_timezone' => $companyTimezone,
            'company_timezone_explicit' => $this->dateTimeDisplay->isCompanyTimezoneExplicit(),
        ]);
    }

    /**
     * Build the scope for the current user's timezone preference.
     */
    private function resolveScope(mixed $user): ?Scope
    {
        if ($user->employee_id) {
            return Scope::employee($user->employee_id, $user->company_id ?? 0);
        }

        if ($user->company_id) {
            return Scope::company($user->company_id);
        }

        return null;
    }

    /**
     * Resolve the IANA timezone identifier for a given mode.
     *
     * LOCAL mode returns null — the browser provides the actual timezone.
     * Delegates company timezone resolution to DateTimeDisplayService.
     */
    private function resolveTimezoneForMode(TimezoneMode $mode, string $companyTimezone): ?string
    {
        return match ($mode) {
            TimezoneMode::COMPANY => $companyTimezone,
            TimezoneMode::UTC => 'UTC',
            TimezoneMode::LOCAL => null,
        };
    }
}
