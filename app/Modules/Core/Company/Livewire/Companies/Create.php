<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\DecodesJsonFields;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\LegalEntityType;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    use DecodesJsonFields;

    public ?int $parentId = null;

    public string $name = '';

    public ?string $code = null;

    public string $status = 'active';

    public ?string $legalName = null;

    public ?string $registrationNumber = null;

    public ?string $taxId = null;

    public ?int $legalEntityTypeId = null;

    public ?string $jurisdiction = null;

    public ?string $email = null;

    public ?string $website = null;

    public string $scopeActivitiesJson = '';

    public string $metadataJson = '';

    public function store(): void
    {
        $validated = $this->validate($this->rules());

        $payload = [
            'parent_id' => $validated['parentId'],
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'],
            'legal_name' => $validated['legalName'],
            'registration_number' => $validated['registrationNumber'],
            'tax_id' => $validated['taxId'],
            'legal_entity_type_id' => $validated['legalEntityTypeId'],
            'jurisdiction' => $validated['jurisdiction'],
            'email' => $validated['email'],
            'website' => $validated['website'],
            'scope_activities' => $this->decodeJsonField($validated['scopeActivitiesJson']),
            'metadata' => $this->decodeJsonField($validated['metadataJson']),
        ];

        Company::query()->create($payload);

        Session::flash('success', __('Company created successfully.'));

        $this->redirect(route('admin.companies.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.companies.create', [
            'parentCompanies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'legalEntityTypes' => LegalEntityType::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'countries' => Country::query()->orderBy('country')->get(['iso', 'country']),
        ]);
    }

    protected function rules(): array
    {
        return [
            'parentId' => ['nullable', 'integer', Rule::exists(Company::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique(Company::class, 'code')],
            'status' => ['required', 'in:active,suspended,pending,archived'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:255'],
            'taxId' => ['nullable', 'string', 'max:255'],
            'legalEntityTypeId' => ['nullable', 'integer', 'exists:company_legal_entity_types,id'],
            'jurisdiction' => ['nullable', 'string', 'max:2', 'exists:geonames_countries,iso'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'scopeActivitiesJson' => ['nullable', 'json'],
            'metadataJson' => ['nullable', 'json'],
        ];
    }
}
