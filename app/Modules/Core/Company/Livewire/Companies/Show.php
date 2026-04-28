<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\Address\Livewire\AbstractAddressForm;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Livewire\Concerns\ManagesCompanyTimezone;
use App\Modules\Core\Company\Livewire\Concerns\SortsCompanyProfileRelations;
use App\Modules\Core\Company\Livewire\Concerns\SortsExternalAccesses;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\LegalEntityType;
use App\Modules\Core\Geonames\Models\Country;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;

class Show extends AbstractAddressForm
{
    use ManagesCompanyTimezone;
    use SavesValidatedFields;
    use SortsCompanyProfileRelations;
    use SortsExternalAccesses;
    use TogglesSort;

    public Company $company;

    public string $childrenSortBy = 'name';

    public string $childrenSortDir = 'asc';

    public string $departmentsSortBy = 'department_type';

    public string $departmentsSortDir = 'asc';

    public string $relationshipsSortBy = 'company_name';

    public string $relationshipsSortDir = 'asc';

    public string $externalAccessesSortBy = 'user';

    public string $externalAccessesSortDir = 'asc';

    private const EXTERNAL_ACCESS_SORTABLE = [
        'user' => true,
        'permissions' => true,
        'access_status' => true,
        'granted_at' => true,
        'expires_at' => true,
    ];

    public int $attachAddressId = 0;

    public array $attachKind = [];

    public bool $attachIsPrimary = false;

    public int $attachPriority = 0;

    public bool $showAttachModal = false;

    public bool $showAddressModal = false;

    public ?int $addressFormId = null;

    public ?string $label = null;

    public ?string $phone = null;

    public ?string $line1 = null;

    public ?string $line2 = null;

    public ?string $line3 = null;

    public array $kind = [];

    public bool $isPrimary = false;

    public int $priority = 0;

    public function mount(Company $company): void
    {
        $this->company = $company->load([
            'parent',
            'legalEntityType',
            'addresses',
            'children.legalEntityType',
            'departments.type',
            'departments.head',
            'relationships.type',
            'relationships.relatedCompany',
            'inverseRelationships.type',
            'inverseRelationships.company',
            'externalAccesses.user',
        ]);

        $this->companyTimezone = app(SettingsService::class)
            ->get('ui.timezone.default', '', Scope::company($company->id)) ?: '';
    }

    public function sortExternalAccesses(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::EXTERNAL_ACCESS_SORTABLE,
            defaultDir: [
                'user' => 'asc',
                'permissions' => 'asc',
                'access_status' => 'asc',
                'granted_at' => 'asc',
                'expires_at' => 'asc',
            ],
            sortByProperty: 'externalAccessesSortBy',
            sortDirProperty: 'externalAccessesSortDir',
            resetPage: false,
        );
    }

    public function saveField(string $field, mixed $value): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'legal_entity_type_id' => ['nullable', 'integer', 'exists:company_legal_entity_types,id'],
            'jurisdiction' => ['nullable', 'string', 'max:2', 'exists:geonames_countries,iso'],
        ];

        $this->saveValidatedField($this->company, $field, $value, $rules);
    }

    public function saveStatus(string $status): void
    {
        if (! in_array($status, ['active', 'suspended', 'pending', 'archived'])) {
            return;
        }

        $this->company->status = $status;
        $this->company->save();
    }

    public function saveParent(?int $parentId): void
    {
        $this->company->parent_id = $parentId ?: null;
        $this->company->save();
        $this->company->load('parent');
    }

    public function addActivity(string $activity): void
    {
        $activity = trim($activity);
        if ($activity === '') {
            return;
        }

        $activities = $this->company->scope_activities ?? [];
        $activities[] = $activity;
        $this->company->scope_activities = array_values(array_unique($activities));
        $this->company->save();
    }

    public function removeActivity(int $index): void
    {
        $activities = $this->company->scope_activities ?? [];
        unset($activities[$index]);
        $this->company->scope_activities = array_values($activities) ?: null;
        $this->company->save();
    }

    public function saveMetadata(string $json): void
    {
        $json = trim($json);

        if ($json === '') {
            $this->company->metadata = null;
            $this->company->save();

            return;
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        $this->company->metadata = $decoded;
        $this->company->save();
    }

    public function updateAddressPivot(int $addressId, string $field, mixed $value): void
    {
        $allowed = ['is_primary', 'priority'];
        if (! in_array($field, $allowed)) {
            return;
        }

        if ($field === 'is_primary') {
            $value = (bool) $value;
        } elseif ($field === 'priority') {
            $value = (int) $value;
        }

        $this->company->addresses()->updateExistingPivot($addressId, [$field => $value]);
    }

    public function saveAddressKinds(int $addressId, array $kinds): void
    {
        $valid = ['headquarters', 'billing', 'shipping', 'branch', 'other'];
        $kinds = array_values(array_intersect($kinds, $valid));

        $this->company->addresses()->updateExistingPivot($addressId, ['kind' => $kinds]);
    }

    public function unlinkAddress(int $addressId): void
    {
        $this->company->addresses()->detach($addressId);
        Session::flash('success', __('Address unlinked.'));
    }

    public function attachAddress(): void
    {
        if ($this->attachAddressId === 0) {
            return;
        }

        $this->company->addresses()->attach($this->attachAddressId, [
            'kind' => $this->attachKind,
            'is_primary' => $this->attachIsPrimary,
            'priority' => $this->attachPriority,
            'valid_from' => now()->toDateString(),
        ]);

        $this->showAttachModal = false;
        $this->reset(['attachAddressId', 'attachKind', 'attachIsPrimary', 'attachPriority']);
        Session::flash('success', __('Address attached.'));
    }

    public function openAddressModal(?int $addressId = null): void
    {
        $this->addressFormId = $addressId;

        if ($addressId === null) {
            $this->resetAddressForm();
        } else {
            $address = Address::query()->findOrFail($addressId);
            $this->label = $address->label ?? '';
            $this->phone = $address->phone;
            $this->line1 = $address->line1;
            $this->line2 = $address->line2;
            $this->line3 = $address->line3;
            $this->countryIso = $address->country_iso;
            $this->admin1Code = $address->admin1Code;
            $this->postcode = $address->postcode;
            $this->locality = $address->locality;
            $this->admin1IsAuto = false;
            $this->localityIsAuto = false;
            $this->admin1Options = $address->country_iso
                ? $this->loadAdmin1ForCountry($address->country_iso)
                : [];
            $this->postcodeOptions = [];
            $localityLookup = ($address->country_iso && $address->postcode)
                ? $this->lookupLocalitiesByPostcode($address->country_iso, $address->postcode)
                : null;
            $this->localityOptions = $localityLookup ? $localityLookup['localities'] : [];
        }

        if ($this->countryIso) {
            $this->ensurePostcodesImported(strtoupper($this->countryIso));
        }

        $this->showAddressModal = true;
    }

    public function saveAddress(): void
    {
        if ($this->addressFormId === null) {
            $this->createAndAttachAddress();
        } else {
            $this->updateAddress();
        }
    }

    /**
     * Render the company show page.
     *
     * Queries addresses and timezone fresh on every render to avoid
     * stale data when edited on the standalone address page.
     */
    public function render(): View
    {
        $this->companyTimezone = app(SettingsService::class)
            ->get('ui.timezone.default', '', Scope::company($this->company->id)) ?: '';

        $addresses = $this->company->addresses()->get();
        $linkedIds = $addresses->pluck('id')->toArray();

        $this->company->loadMissing([
            'children.legalEntityType',
            'departments.type',
            'departments.head',
            'relationships.type',
            'relationships.relatedCompany',
            'inverseRelationships.type',
            'inverseRelationships.company',
            'externalAccesses.user',
        ]);

        return view('livewire.admin.companies.show', [
            'sortedChildren' => $this->sortChildrenCollection(collect($this->company->children)),
            'sortedDepartments' => $this->sortDepartmentsCollection(collect($this->company->departments)),
            'sortedRelationships' => $this->sortRelationshipsCollection($this->allRelationshipRows()),
            'sortedExternalAccesses' => $this->sortExternalAccessesByColumn(
                collect($this->company->externalAccesses),
                $this->externalAccessesSortBy,
                $this->externalAccessesSortDir,
                'user',
            ),
            'addresses' => $addresses,
            'availableAddresses' => Address::query()
                ->whereNotIn('id', $linkedIds)
                ->orderBy('label')
                ->get(['id', 'label', 'line1', 'locality', 'country_iso']),
            'parentCompanies' => Company::query()
                ->where('id', '!=', $this->company->id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'legalEntityTypes' => LegalEntityType::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'countries' => Country::query()->orderBy('country')->get(['iso', 'country']),
            'timezoneOptions' => collect(DateTimeZone::listIdentifiers())->map(fn (string $tz) => [
                'value' => $tz,
                'label' => $tz,
            ])->all(),
        ]);
    }

    protected function resetAddressForm(): void
    {
        $this->label = null;
        $this->phone = null;
        $this->line1 = null;
        $this->line2 = null;
        $this->line3 = null;
        $this->kind = [];
        $this->isPrimary = false;
        $this->priority = 0;
        $this->resetAddressFormGeoState();
        $this->countryIso = null;
    }

    protected function createAndAttachAddress(): void
    {
        $validated = $this->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string'],
            'line2' => ['nullable', 'string'],
            'line3' => ['nullable', 'string'],
            'locality' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'countryIso' => ['nullable', 'string', 'size:2'],
            'admin1Code' => ['nullable', 'string', 'max:20'],
            'kind' => ['required', 'array', 'min:1'],
            'kind.*' => ['string', 'in:headquarters,billing,shipping,branch,other'],
            'isPrimary' => ['boolean'],
            'priority' => ['integer'],
        ]);

        $address = Address::query()->create([
            'label' => $validated['label'],
            'phone' => $validated['phone'],
            'line1' => $validated['line1'],
            'line2' => $validated['line2'],
            'line3' => $validated['line3'],
            'locality' => $validated['locality'],
            'postcode' => $validated['postcode'],
            'country_iso' => $validated['countryIso'] ? strtoupper($validated['countryIso']) : null,
            'admin1Code' => $validated['admin1Code'],
            'source' => 'manual',
            'verificationStatus' => 'unverified',
        ]);

        $this->company->addresses()->attach($address->id, [
            'kind' => $validated['kind'],
            'is_primary' => $validated['isPrimary'],
            'priority' => $validated['priority'],
            'valid_from' => now()->toDateString(),
        ]);

        $this->showAddressModal = false;
        $this->resetAddressForm();
        $this->checkTimezoneSuggestion($this->company, $address);
        Session::flash('success', __('Address created and attached.'));
    }

    protected function updateAddress(): void
    {
        $validated = $this->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string'],
            'line2' => ['nullable', 'string'],
            'line3' => ['nullable', 'string'],
            'locality' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'countryIso' => ['nullable', 'string', 'size:2'],
            'admin1Code' => ['nullable', 'string', 'max:20'],
        ]);

        $address = Address::query()->findOrFail($this->addressFormId);
        $address->update([
            'label' => $validated['label'],
            'phone' => $validated['phone'],
            'line1' => $validated['line1'],
            'line2' => $validated['line2'],
            'line3' => $validated['line3'],
            'locality' => $validated['locality'],
            'postcode' => $validated['postcode'],
            'country_iso' => $validated['countryIso'] ? strtoupper($validated['countryIso']) : null,
            'admin1Code' => $validated['admin1Code'],
        ]);

        $this->showAddressModal = false;
        $this->resetAddressForm();

        if ($address->wasChanged(['country_iso', 'admin1Code', 'postcode', 'locality'])) {
            $this->checkTimezoneSuggestion($this->company, $address);
        }

        Session::flash('success', __('Address updated.'));
    }
}
