<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Livewire\Addresses;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\Address\Livewire\AbstractAddressForm;
use App\Modules\Core\Address\Models\Address;
use Illuminate\Support\Facades\DB;

class Show extends AbstractAddressForm
{
    use SavesValidatedFields;

    public Address $address;

    public function mount(Address $address): void
    {
        $this->address = $address->load(['country', 'admin1']);
        $this->country_iso = $address->country_iso;
        $this->admin1_code = $address->admin1_code;
        $this->postcode = $address->postcode;
        $this->locality = $address->locality;

        if ($this->country_iso) {
            $this->admin1Options = $this->loadAdmin1ForCountry($this->country_iso);
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
                ['country_iso' => $iso],
                ['country_iso' => ['string', 'size:2']]
            )->validate();

            $this->address->country_iso = strtoupper($validated['country_iso']);
        }

        $this->address->save();
        $this->address->load(['country']);
    }

    public function updatedCountryIso($value): void
    {
        $this->saveCountry($value ?? '');
        parent::updatedCountryIso($value);
        $this->address->admin1_code = null;
        $this->address->postcode = null;
        $this->address->locality = null;
        $this->address->save();
    }

    public function updatedPostcode($value): void
    {
        $this->address->postcode = $value;
        $this->address->save();
        parent::updatedPostcode($value);
        $this->address->admin1_code = $this->admin1_code;
        $this->address->locality = $this->locality;
        $this->address->save();
    }

    public function updatedAdmin1Code($value = null): void
    {
        parent::updatedAdmin1Code($value);
        $this->address->admin1_code = $value ?? $this->admin1_code;
        $this->address->save();
    }

    public function updatedLocality($value = null): void
    {
        parent::updatedLocality($value);
        $this->address->locality = $value ?? $this->locality;
        $this->address->save();
    }

    public function saveVerificationStatus(string $status): void
    {
        if (! in_array($status, ['unverified', 'suggested', 'verified'])) {
            return;
        }

        $this->address->verification_status = $status;
        $this->address->save();
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
                'kind' => json_decode($row->kind, true) ?? [],
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

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.addresses.show', $this->with());
    }
}
