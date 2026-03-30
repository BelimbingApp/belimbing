<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\DateTime\Controllers;

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles timezone display mode changes.
 *
 * Called from the top-bar Alpine toggle via fetch().
 * Cycles through Company → Local → UTC → Company.
 */
class TimezoneController
{
    private const SETTINGS_KEY = 'ui.timezone.mode';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Cycle the timezone display mode for the authenticated user.
     *
     * Persists at the most specific available scope (employee or company).
     * Returns the new mode so the UI can update immediately.
     */
    public function cycle(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $this->resolveScope($user);

        $currentRaw = $this->settings->get(self::SETTINGS_KEY, TimezoneMode::COMPANY->value, $scope);
        $current = TimezoneMode::tryFrom($currentRaw) ?? TimezoneMode::COMPANY;

        $next = match ($current) {
            TimezoneMode::COMPANY => TimezoneMode::LOCAL,
            TimezoneMode::LOCAL => TimezoneMode::UTC,
            TimezoneMode::UTC => TimezoneMode::COMPANY,
        };

        $this->settings->set(self::SETTINGS_KEY, $next->value, $scope);

        return response()->json([
            'mode' => $next->value,
            'label' => $this->label($next),
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
     * Human-readable label for a timezone mode.
     */
    private function label(TimezoneMode $mode): string
    {
        return match ($mode) {
            TimezoneMode::COMPANY => __('Company'),
            TimezoneMode::LOCAL => __('Local'),
            TimezoneMode::UTC => __('UTC'),
        };
    }
}
