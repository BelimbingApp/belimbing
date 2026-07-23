<?php

namespace App\Base\DateTime\Controllers;

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
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
    public function __construct(
        private readonly TimezoneSettings $timezoneSettings,
        private readonly DateTimeDisplayService $dateTimeDisplay,
    ) {}

    /**
     * Set the timezone display mode for the authenticated user.
     *
     * Persists against the authenticated user account.
     * Returns the new mode and resolved IANA timezone identifier.
     */
    public function set(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(array_column(TimezoneMode::cases(), 'value'))],
        ]);

        $user = $request->user();
        $mode = TimezoneMode::from($validated['mode']);

        abort_if($user === null, 401);

        $this->timezoneSettings->setModeForUser(
            (int) $user->getAuthIdentifier(),
            $mode,
        );

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
