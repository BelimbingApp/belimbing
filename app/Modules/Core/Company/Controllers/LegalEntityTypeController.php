<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Controllers;

use App\Modules\Core\Company\Models\LegalEntityType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LegalEntityTypeController
{
    /**
     * Show legal entity types page.
     */
    public function index(): View
    {
        $types = LegalEntityType::query()
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('companies.legal-entity-types', compact('types'));
    }

    /**
     * Create a legal entity type.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('company_legal_entity_types', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        LegalEntityType::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        Session::flash('success', __('Legal entity type created.'));

        return redirect()->route('admin.companies.legal-entity-types');
    }

    /**
     * Update a legal entity type.
     */
    public function update(Request $request, LegalEntityType $legalEntityType): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $legalEntityType->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        Session::flash('success', __('Legal entity type updated.'));

        return redirect()->route('admin.companies.legal-entity-types');
    }

    /**
     * Delete a legal entity type.
     */
    public function destroy(LegalEntityType $legalEntityType): RedirectResponse
    {
        $legalEntityType->loadCount('companies');

        if ($legalEntityType->companies_count > 0) {
            Session::flash('error', __('Cannot delete a legal entity type that is in use by companies.'));

            return redirect()->route('admin.companies.legal-entity-types');
        }

        $legalEntityType->delete();
        Session::flash('success', __('Legal entity type deleted.'));

        return redirect()->route('admin.companies.legal-entity-types');
    }
}
