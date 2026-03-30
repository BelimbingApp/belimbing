<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Livewire\Addresses;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\Address\Livewire\AbstractAddressForm;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Services\CompanyTimezoneResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class Show extends AbstractAddressForm
{
    use SavesValidatedFields;

    public Address $address;

    #[Url(as: 'company')]
    public ?int $companyContextId = null;

    public ?string $suggestedTimezone = null;

    public ?string $suggestedTimezoneOld = null;

    public bool $timezoneWasAutoApplied = false;

    public function mount(Address $address): void
    {
        $this->address = $address->load(['country', 'admin1']);
        $this->countryIso = $address->country_iso;
        $this->admin1Code = $address->admin1Code;
        $this->postcode = $address->postcode;
        $this->locality = $address->locality;

        if ($this->countryIso) {
            $this->admin1Options = $this->loadAdmin1ForCountry($this->countryIso);
        }

        if ($this->companyContextId) {
            $exists = Company::query()
                ->whereHas('addresses', fn ($q) => $q->where('addresses.id', $address->id))
                ->where('id', $this->companyContextId)
                ->exists();

            if (! $exists) {
                $this->companyContextId = null;
            }
        }
    }

    public function saveField(string $field, mixed $value): void
    {
        $this->saveValidatedField($this->address, $field, $value, Address::fieldRules());
    }

    public function saveCountry(string $iso): void
    {
        if ($iso === '') {
            $this->address->country_iso = null;
        } else {
            $validated = validator(
                ['countryIso' => $iso],
                ['countryIso' => ['string', 'size:2']]
            )->validate();

            $this->address->country_iso = strtoupper($validated['countryIso']);
        }

        $this->address->save();
        $this->address->load(['country']);
    }

    public function updatedCountryIso($value): void
    {
        $this->saveCountry($value ?? '');
        parent::updatedCountryIso($value);
        $this->address->admin1Code = null;
        $this->address->postcode = null;
        $this->address->locality = null;
        $this->address->save();
        $this->checkCompanyTimezone();
    }

    public function updatedPostcode($value): void
    {
        $this->address->postcode = $value;
        $this->address->save();
        parent::updatedPostcode($value);
        $this->address->admin1Code = $this->admin1Code;
        $this->address->locality = $this->locality;
        $this->address->save();
        $this->checkCompanyTimezone();
    }

    public function updatedAdmin1Code($value = null): void
    {
        parent::updatedAdmin1Code($value);
        $this->address->admin1Code = $value ?? $this->admin1Code;
        $this->address->save();
    }

    public function updatedLocality($value = null): void
    {
        parent::updatedLocality($value);
        $this->address->locality = $value ?? $this->locality;
        $this->address->save();
        $this->checkCompanyTimezone();
    }

    public function saveVerificationStatus(string $status): void
    {
        if (! in_array($status, ['unverified', 'suggested', 'verified'])) {
            return;
        }

        $this->address->verificationStatus = $status;
        $this->address->save();
    }

    /**
     * Accept the suggested timezone and persist it for the company context.
     */
    public function acceptSuggestedTimezone(): void
    {
        if (! $this->suggestedTimezone || ! $this->companyContextId) {
            return;
        }

        $company = Company::query()->find($this->companyContextId);

        if (! $company) {
            return;
        }

        $resolver = app(CompanyTimezoneResolver::class);
        $resolver->apply($company, $this->suggestedTimezone);
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

    /**
     * Check whether the address geo change warrants a company timezone update.
     *
     * Resolves the company from the URL context or, when unambiguous,
     * from the single linked company. Only acts on the primary address.
     */
    protected function checkCompanyTimezone(): void
    {
        $company = $this->resolveCompanyForTimezone();

        if (! $company) {
            return;
        }

        $resolver = app(CompanyTimezoneResolver::class);
        $decision = $resolver->decide($company, $this->address);

        if (! $decision) {
            $this->suggestedTimezone = null;
            $this->suggestedTimezoneOld = null;

            return;
        }

        if ($decision['action'] === 'auto-save') {
            $resolver->apply($company, $decision['timezone']);
            $this->timezoneWasAutoApplied = true;
            $this->suggestedTimezone = null;
            $this->suggestedTimezoneOld = null;
        } elseif ($decision['action'] === 'prompt') {
            $this->suggestedTimezone = $decision['timezone'];
            $this->suggestedTimezoneOld = $decision['current'];
        }
    }

    /**
     * Resolve the company context for timezone decisions.
     *
     * Prefers the explicit URL context. Falls back to the single linked
     * company when the address belongs to exactly one.
     */
    protected function resolveCompanyForTimezone(): ?Company
    {
        if ($this->companyContextId) {
            return Company::query()->find($this->companyContextId);
        }

        $companyIds = DB::table('addressables')
            ->where('address_id', $this->address->id)
            ->where('addressable_type', Company::class)
            ->pluck('addressable_id');

        if ($companyIds->count() === 1) {
            return Company::query()->find($companyIds->first());
        }

        return null;
    }

    public function with(): array
    {
        $linkedEntities = DB::table('addressables')
            ->where('address_id', $this->address->id)
            ->get();

        $entities = $linkedEntities->map(function ($row) {
            $model = $row->addressable_type::find($row->addressable_id);

            return (object) [
                'model' => $model,
                'type' => class_basename($row->addressable_type),
                'kind' => BlbJson::decodeArray($row->kind) ?? [],
                'is_primary' => $row->is_primary,
                'priority' => $row->priority,
                'valid_from' => $row->valid_from,
                'valid_to' => $row->valid_to,
            ];
        })->filter(fn ($e) => $e->model !== null);

        return [
            'linkedEntities' => $entities,
            'countryOptions' => $this->loadCountryOptionsForCombobox(),
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.addresses.show', $this->with());
    }
}
