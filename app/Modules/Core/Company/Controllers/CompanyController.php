<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Controllers;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\LegalEntityType;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanyController
{
    /**
     * Show the companies list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $companies = $this->companiesQuery($search)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('companies.index', compact('companies', 'search'));
    }

    /**
     * Return the company table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $companies = $this->companiesQuery($search)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('companies.partials.table', compact('companies', 'search'));
    }

    /**
     * Show the company create form.
     */
    public function create(): View
    {
        $parentCompanies = Company::query()->orderBy('name')->get(['id', 'name']);
        $legalEntityTypes = LegalEntityType::query()->active()->orderBy('name')->get(['id', 'name']);
        $countries = Country::query()->orderBy('country')->get(['iso', 'country']);

        return view('companies.create', compact('parentCompanies', 'legalEntityTypes', 'countries'));
    }

    /**
     * Store a new company.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists(Company::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique(Company::class, 'code')],
            'status' => ['required', 'in:active,suspended,pending,archived'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'legal_entity_type_id' => ['nullable', 'integer', Rule::exists(LegalEntityType::class, 'id')],
            'jurisdiction' => ['nullable', 'string', 'max:2', 'exists:geonames_countries,iso'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'scope_activities' => ['nullable', 'json'],
            'scope_activities_json' => ['nullable', 'json'],
            'metadata' => ['nullable', 'json'],
            'metadata_json' => ['nullable', 'json'],
        ]);

        $scopeActivitiesJson = $validated['scope_activities_json'] ?? $validated['scope_activities'] ?? null;
        $metadataJson = $validated['metadata_json'] ?? $validated['metadata'] ?? null;

        unset($validated['scope_activities_json'], $validated['metadata_json']);

        $validated['scope_activities'] = $this->decodeJsonField($scopeActivitiesJson);
        $validated['metadata'] = $this->decodeJsonField($metadataJson);

        Company::query()->create($validated);

        Session::flash('success', __('Company created successfully.'));

        return redirect()->route('admin.companies.index');
    }

    /**
     * Show the company details page.
     */
    public function show(Company $company): View
    {
        $company->load([
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

        $parentCompanies = Company::query()
            ->where('id', '!=', $company->id)
            ->orderBy('name')
            ->get(['id', 'name']);
        $legalEntityTypes = LegalEntityType::query()->active()->orderBy('name')->get(['id', 'name']);
        $countries = Country::query()->orderBy('country')->get(['iso', 'country']);

        return view('companies.show', compact('company', 'parentCompanies', 'legalEntityTypes', 'countries'));
    }

    /**
     * Delete a company.
     */
    public function destroy(Request $request, Company $company): RedirectResponse
    {
        $company->loadCount('children');

        if ($company->isLicensee()) {
            Session::flash('error', __('The licensee company cannot be deleted.'));

            return redirect()->route('admin.companies.index');
        }

        if ($company->children_count > 0) {
            Session::flash('error', __('Cannot delete a company that has subsidiaries.'));

            return redirect()->route('admin.companies.index');
        }

        $company->delete();
        Session::flash('success', __('Company deleted successfully.'));

        return redirect()->route('admin.companies.index');
    }

    /**
     * Update an inline company field.
     */
    public function updateField(Request $request, Company $company): JsonResponse|Response
    {
        $field = $request->string('field')->toString();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique(Company::class, 'code')->ignore($company->id)],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,suspended,pending,archived'],
            'legal_entity_type_id' => ['nullable', 'integer', Rule::exists(LegalEntityType::class, 'id')],
            'jurisdiction' => ['nullable', 'string', 'max:2', 'exists:geonames_countries,iso'],
            'parent_id' => ['nullable', 'integer', Rule::exists(Company::class, 'id')->whereNot('id', $company->id)],
            'metadata' => ['nullable', 'json'],
        ];

        if (! isset($rules[$field])) {
            return response()->json(['message' => __('Unsupported field.')], 422);
        }

        $value = $request->input('value');
        if (is_string($value) && trim($value) === '' && $field !== 'status') {
            $value = null;
        }

        $validated = validator(['value' => $value], ['value' => $rules[$field]])->validate();

        $value = $validated['value'];
        if ($field === 'metadata' && is_string($value)) {
            $value = json_decode($value, true);
        }

        $company->{$field} = $value;
        $company->save();

        if ($request->header('HX-Request') === 'true') {
            return response()->noContent();
        }

        return response()->json(['message' => __('Company updated.')]);
    }

    /**
     * Build the filtered company list query.
     */
    private function companiesQuery(string $search): Builder
    {
        return Company::query()
            ->with('parent')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('legal_name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('jurisdiction', 'like', '%'.$search.'%');
                });
            });
    }

    /**
     * Decode a JSON string field to an array.
     */
    private function decodeJsonField(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
