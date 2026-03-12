<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Livewire\Addresses;

use App\Modules\Core\Address\Livewire\AbstractAddressForm;
use App\Modules\Core\Address\Models\Address;
use Illuminate\Support\Facades\Session;

class Create extends AbstractAddressForm
{
    public ?string $label = null;

    public ?string $phone = null;

    public ?string $line1 = null;

    public ?string $line2 = null;

    public ?string $line3 = null;

    public ?string $rawInput = null;

    public ?string $source = 'manual';

    public ?string $sourceRef = null;

    public ?string $parserVersion = null;

    public ?string $parseConfidence = null;

    public string $verificationStatus = 'unverified';

    public function with(): array
    {
        return [
            'countryOptions' => $this->loadCountryOptionsForCombobox(),
        ];
    }

    public function store(): void
    {
        $validated = $this->validate($this->rules());

        Address::query()->create([
            'label' => $validated['label'],
            'phone' => $validated['phone'],
            'line1' => $validated['line1'],
            'line2' => $validated['line2'],
            'line3' => $validated['line3'],
            'locality' => $validated['locality'],
            'postcode' => $validated['postcode'],
            'country_iso' => $validated['countryIso'] ? strtoupper($validated['countryIso']) : null,
            'admin1Code' => $validated['admin1Code'],
            'rawInput' => $validated['rawInput'],
            'source' => $validated['source'],
            'sourceRef' => $validated['sourceRef'],
            'parserVersion' => $validated['parserVersion'],
            'parseConfidence' => $validated['parseConfidence'] !== null
                ? (float) $validated['parseConfidence']
                : null,
            'verificationStatus' => $validated['verificationStatus'],
        ]);

        Session::flash('success', __('Address created successfully.'));

        $this->redirect(route('admin.addresses.index'), navigate: true);
    }

    protected function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string'],
            'line2' => ['nullable', 'string'],
            'line3' => ['nullable', 'string'],
            'locality' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'sourceRef' => ['nullable', 'string', 'max:255'],
            'rawInput' => ['nullable', 'string'],
            'countryIso' => ['nullable', 'string', 'size:2'],
            'admin1Code' => ['nullable', 'string', 'max:20'],
            'parserVersion' => ['nullable', 'string', 'max:255'],
            'parseConfidence' => ['nullable', 'numeric', 'between:0,1'],
            'verificationStatus' => ['required', 'in:unverified,suggested,verified'],
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.addresses.create', $this->with());
    }
}
