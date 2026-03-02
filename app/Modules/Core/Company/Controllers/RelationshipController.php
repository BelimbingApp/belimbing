<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Controllers;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\RelationshipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RelationshipController
{
    /**
     * Show relationships for a company.
     */
    public function index(Company $company): View
    {
        $outgoing = CompanyRelationship::query()
            ->where('company_id', $company->id)
            ->with(['relatedCompany', 'type'])
            ->get()
            ->map(fn (CompanyRelationship $relationship): object => (object) [
                'relationship' => $relationship,
                'direction' => 'outgoing',
                'other' => $relationship->relatedCompany,
            ]);

        $incoming = CompanyRelationship::query()
            ->where('related_company_id', $company->id)
            ->with(['company', 'type'])
            ->get()
            ->map(fn (CompanyRelationship $relationship): object => (object) [
                'relationship' => $relationship,
                'direction' => 'incoming',
                'other' => $relationship->company,
            ]);

        $allRelationships = $outgoing->merge($incoming);

        $availableCompanies = Company::query()
            ->where('id', '!=', $company->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $relationshipTypes = RelationshipType::query()->active()->orderBy('name')->get(['id', 'name']);

        return view('companies.relationships', compact('company', 'allRelationships', 'availableCompanies', 'relationshipTypes'));
    }

    /**
     * Create a relationship for a company.
     */
    public function store(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'related_company_id' => ['required', 'integer', Rule::exists(Company::class, 'id')->whereNot('id', $company->id)],
            'relationship_type_id' => ['required', 'integer', Rule::exists(RelationshipType::class, 'id')],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        CompanyRelationship::query()->create([
            'company_id' => $company->id,
            'related_company_id' => (int) $validated['related_company_id'],
            'relationship_type_id' => (int) $validated['relationship_type_id'],
            'effective_from' => $validated['effective_from'] ?? null,
            'effective_to' => $validated['effective_to'] ?? null,
        ]);

        Session::flash('success', __('Relationship created.'));

        return redirect()->route('admin.companies.relationships', $company);
    }

    /**
     * Delete a relationship.
     */
    public function destroy(Company $company, CompanyRelationship $relationship): RedirectResponse
    {
        if ($relationship->company_id !== $company->id && $relationship->related_company_id !== $company->id) {
            abort(404);
        }

        $relationship->delete();
        Session::flash('success', __('Relationship deleted.'));

        return redirect()->route('admin.companies.relationships', $company);
    }
}
