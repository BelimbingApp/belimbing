<?php

namespace App\Modules\Core\Company\Livewire\Concerns;

use App\Base\DateTime\Services\TimezoneSettings;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Services\CompanyTimezoneResolver;
use DateTimeZone;

/**
 * Manages company timezone persistence and locality-driven suggestions.
 *
 * Provides inline save via updatedCompanyTimezone(), and a decision flow
 * that auto-saves when the company has no timezone or prompts the user
 * when the suggested timezone differs from the current one.
 */
trait ManagesCompanyTimezone
{
    public ?string $companyTimezone = '';

    public ?string $suggestedTimezone = null;

    public ?string $suggestedTimezoneOld = null;

    public bool $timezoneWasAutoApplied = false;

    /**
     * Persist the selected IANA timezone as the company default.
     *
     * Validates against PHP's known timezone identifiers.
     * Clearing the value removes the setting, falling back to UTC.
     *
     * @param  string  $value  The IANA timezone identifier
     */
    public function updatedCompanyTimezone(?string $value): void
    {
        $this->persistCompanyTimezone($value);
    }

    /**
     * @return string|null Normalized timezone, or null when validation fails.
     */
    private function persistCompanyTimezone(?string $value): ?string
    {
        $tz = trim((string) $value);

        if ($tz !== '' && ! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        $timezoneSettings = app(TimezoneSettings::class);

        if ($tz === '') {
            $timezoneSettings->forgetCompanyTimezone((int) $this->company->id);
            $this->dispatch('timezone-saved', timezone: '');
        } else {
            $timezoneSettings->setCompanyTimezone((int) $this->company->id, $tz);
            $this->dispatch('timezone-saved', timezone: $tz);
        }

        return $tz;
    }

    /**
     * Check timezone suggestion after an address geo change.
     *
     * Auto-saves when the company has no timezone set. When a timezone
     * already exists and differs from the suggestion, stores the suggestion
     * for the UI to prompt the user.
     */
    protected function checkTimezoneSuggestion(Company $company, Address $address): void
    {
        $resolver = app(CompanyTimezoneResolver::class);
        $decision = $resolver->decide($company, $address);

        if (! $decision) {
            $this->suggestedTimezone = null;
            $this->suggestedTimezoneOld = null;

            return;
        }

        if ($decision['action'] === 'auto-save') {
            $resolver->apply($company, $decision['timezone']);
            $this->companyTimezone = $decision['timezone'];
            $this->timezoneWasAutoApplied = true;
            $this->suggestedTimezone = null;
            $this->suggestedTimezoneOld = null;
        } elseif ($decision['action'] === 'prompt') {
            $this->suggestedTimezone = $decision['timezone'];
            $this->suggestedTimezoneOld = $decision['current'];
        }
    }

    /**
     * Accept the suggested timezone and persist it.
     */
    public function acceptSuggestedTimezone(): void
    {
        if (! $this->suggestedTimezone) {
            return;
        }

        $resolver = app(CompanyTimezoneResolver::class);
        $resolver->apply($this->company, $this->suggestedTimezone);
        $this->companyTimezone = $this->suggestedTimezone;
        $this->timezoneWasAutoApplied = true;
        $this->suggestedTimezone = null;
        $this->suggestedTimezoneOld = null;
    }

    /**
     * Dismiss the timezone suggestion without applying.
     */
    public function dismissSuggestedTimezone(): void
    {
        $this->suggestedTimezone = null;
        $this->suggestedTimezoneOld = null;
    }
}
