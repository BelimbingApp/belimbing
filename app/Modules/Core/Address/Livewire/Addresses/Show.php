<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Livewire\Addresses;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\Address\Livewire\AbstractAddressForm;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Services\CompanyTimezoneResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class Show extends AbstractAddressForm
{
    use SavesValidatedFields;
    use TogglesSort;

    public Address $address;

    #[Url(as: 'company')]
    public ?int $companyContextId = null;

    public ?string $suggestedTimezone = null;

    public ?string $suggestedTimezoneOld = null;

    public bool $timezoneWasAutoApplied = false;

    public string $linkedSortBy = 'type';

    public string $linkedSortDir = 'asc';

    private const LINKED_SORTABLE = [
        'type' => true,
        'name' => true,
        'kind' => true,
        'is_primary' => true,
        'priority' => true,
        'valid_from' => true,
        'valid_to' => true,
    ];

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

    public function sortLinked(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::LINKED_SORTABLE,
            defaultDir: [
                'type' => 'asc',
                'name' => 'asc',
                'kind' => 'asc',
                'is_primary' => 'desc',
                'priority' => 'asc',
                'valid_from' => 'asc',
                'valid_to' => 'asc',
            ],
            sortByProperty: 'linkedSortBy',
            sortDirProperty: 'linkedSortDir',
            resetPage: false,
        );
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

        $entities = $this->sortLinkedEntities($entities);

        return [
            'linkedEntities' => $entities,
            'countryOptions' => $this->loadCountryOptionsForCombobox(),
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.addresses.show', $this->with());
    }

    /**
     * @param  Collection<int, object{model: mixed, type: string, kind: array<int, string>, is_primary: mixed, priority: mixed, valid_from: mixed, valid_to: mixed}>  $entities
     * @return Collection<int, object{model: mixed, type: string, kind: array<int, string>, is_primary: mixed, priority: mixed, valid_from: mixed, valid_to: mixed}>
     */
    private function sortLinkedEntities(Collection $entities): Collection
    {
        $dir = $this->linkedSortDir === 'desc' ? -1 : 1;

        return $entities
            ->sort(function (object $a, object $b) use ($dir): int {
                $nameA = $this->linkedEntitySortName($a);
                $nameB = $this->linkedEntitySortName($b);
                $kindKey = function (object $entity): string {
                    $kinds = $entity->kind;
                    $kinds = is_array($kinds) ? $kinds : [];
                    sort($kinds);

                    return implode(',', $kinds);
                };

                $primary = match ($this->linkedSortBy) {
                    'type' => $dir * strcmp((string) $a->type, (string) $b->type),
                    'name' => $dir * strcmp($nameA, $nameB),
                    'kind' => $dir * strcmp($kindKey($a), $kindKey($b)),
                    'is_primary' => $dir * (((int) (bool) $a->is_primary) <=> ((int) (bool) $b->is_primary)),
                    'priority' => $dir * (((int) ($a->priority ?? 0)) <=> ((int) ($b->priority ?? 0))),
                    'valid_from' => $dir * strcmp((string) ($a->valid_from ?? ''), (string) ($b->valid_from ?? '')),
                    'valid_to' => $dir * strcmp((string) ($a->valid_to ?? ''), (string) ($b->valid_to ?? '')),
                    default => $dir * strcmp((string) $a->type, (string) $b->type),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return ($a->model->id ?? 0) <=> ($b->model->id ?? 0);
            })
            ->values();
    }

    private function linkedEntitySortName(object $entity): string
    {
        $model = $entity->model;

        if ($entity->type === 'Company') {
            return (string) ($model->name ?? '');
        }

        if ($entity->type === 'Employee') {
            return (string) ($model->full_name ?? '');
        }

        return (string) ($model->name ?? (string) $model->id);
    }
}
