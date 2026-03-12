<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Livewire;

use App\Modules\Core\Address\Concerns\HasAddressGeoLookups;
use Livewire\Component;

abstract class AbstractAddressForm extends Component
{
    use HasAddressGeoLookups;

    public ?string $countryIso = null;

    public ?string $admin1Code = null;

    public array $admin1Options = [];

    public ?string $postcode = null;

    public array $postcodeOptions = [];

    public ?string $locality = null;

    public array $localityOptions = [];

    public bool $admin1IsAuto = false;

    public bool $localityIsAuto = false;

    /**
     * Reset geo-related form state when country changes.
     */
    public function updatedCountryIso($value): void
    {
        $this->admin1Code = null;
        $this->admin1IsAuto = false;
        $this->admin1Options = [];
        $this->postcode = null;
        $this->postcodeOptions = [];
        $this->locality = null;
        $this->localityIsAuto = false;
        $this->localityOptions = [];

        if ($value) {
            $this->ensurePostcodesImported(strtoupper($value));
            $this->admin1Options = $this->loadAdmin1ForCountry($value);
        }
    }

    /**
     * Run postcode lookup and apply result to geo state.
     * Uses country from getCountryIsoForLookup() for the lookup.
     */
    public function updatedPostcode($value): void
    {
        $countryIso = $this->getCountryIsoForLookup();

        if (! $countryIso || ! $value) {
            $this->localityOptions = [];

            return;
        }

        if ($this->admin1IsAuto) {
            $this->admin1Code = null;
            $this->admin1IsAuto = false;
        }
        if ($this->localityIsAuto) {
            $this->locality = null;
            $this->localityIsAuto = false;
        }

        $result = $this->lookupLocalitiesByPostcode($countryIso, $value);

        if (! $result) {
            $this->localityOptions = [];

            return;
        }

        $this->localityOptions = $result['localities'];

        if (count($result['localities']) === 1) {
            $this->locality = $result['localities'][0]['value'];
            $this->localityIsAuto = true;
        }

        if ($result['admin1Code']) {
            $this->admin1Code = $result['admin1Code'];
            $this->admin1IsAuto = true;

            if (empty($this->admin1Options)) {
                $this->admin1Options = $this->loadAdmin1ForCountry($countryIso);
            }
        }
    }

    public function updatedAdmin1Code($value = null): void
    {
        $this->admin1IsAuto = false;
    }

    public function updatedLocality($value = null): void
    {
        $this->localityIsAuto = false;
    }

    /**
     * Country ISO used for postcode lookup. Override when form state differs from persisted model.
     */
    protected function getCountryIsoForLookup(): ?string
    {
        return $this->countryIso;
    }

    /**
     * Reset address form geo state (for modal open/close). Call from subclass when needed.
     */
    protected function resetAddressFormGeoState(): void
    {
        $this->admin1Code = null;
        $this->admin1IsAuto = false;
        $this->admin1Options = [];
        $this->postcode = null;
        $this->postcodeOptions = [];
        $this->locality = null;
        $this->localityIsAuto = false;
        $this->localityOptions = [];
    }
}
