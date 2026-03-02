<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Http\Controllers;

use App\Modules\Core\Address\Concerns\HasAddressGeoLookups;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AddressController
{
    use HasAddressGeoLookups;

    /**
     * Show the address list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $addresses = $this->buildAddressQuery($search)
            ->paginate(15)
            ->withQueryString();

        return view('addresses.index', compact('addresses', 'search'));
    }

    /**
     * Return the searchable table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $addresses = $this->buildAddressQuery($search)
            ->paginate(15)
            ->withQueryString();

        return view('addresses.partials.table', compact('addresses', 'search'));
    }

    /**
     * Show the create-address form.
     */
    public function create(): View
    {
        return view('addresses.create', [
            'countryOptions' => Country::query()->orderBy('country')->get(['iso', 'country']),
            'admin1Options' => [],
        ]);
    }

    /**
     * Store a newly created address.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->storeRules());

        $validated['country_iso'] = $this->normalizeNullableString($validated['country_iso'] ?? null);
        $validated['admin1_code'] = $this->normalizeNullableString($validated['admin1_code'] ?? null);
        $validated['postcode'] = $this->normalizeNullableString($validated['postcode'] ?? null);
        $validated['locality'] = $this->normalizeNullableString($validated['locality'] ?? null);

        if ($validated['country_iso'] !== null) {
            $validated['country_iso'] = strtoupper($validated['country_iso']);
        }

        $validated['parse_confidence'] = isset($validated['parse_confidence']) && $validated['parse_confidence'] !== null
            ? (float) $validated['parse_confidence']
            : null;

        Address::query()->create($validated);

        Session::flash('success', __('Address created successfully.'));

        return redirect()->route('admin.addresses.index');
    }

    /**
     * Show the address detail page.
     */
    public function show(Address $address): View
    {
        $address->load(['country', 'admin1']);

        $linkedEntities = DB::table('addressables')
            ->where('address_id', $address->id)
            ->get()
            ->map(function ($row) {
                $model = $row->addressable_type::query()->find($row->addressable_id);

                return (object) [
                    'model' => $model,
                    'type' => class_basename($row->addressable_type),
                    'kind' => json_decode($row->kind, true) ?? [],
                    'is_primary' => $row->is_primary,
                    'priority' => $row->priority,
                    'valid_from' => $row->valid_from,
                    'valid_to' => $row->valid_to,
                ];
            })
            ->filter(fn ($entity) => $entity->model !== null);

        $admin1Options = $address->country_iso
            ? $this->loadAdmin1ForCountry($address->country_iso)
            : [];

        return view('addresses.show', [
            'address' => $address,
            'linkedEntities' => $linkedEntities,
            'countryOptions' => Country::query()->orderBy('country')->get(['iso', 'country']),
            'admin1Options' => $admin1Options,
        ]);
    }

    /**
     * Update a non-geo field on the address.
     */
    public function updateField(Request $request, Address $address): RedirectResponse
    {
        $field = $request->string('field', '')->toString();
        $rules = $this->updatableFieldRules();

        if (! isset($rules[$field])) {
            return redirect()->route('admin.addresses.show', $address);
        }

        $value = $this->normalizeNullableString($request->input('value'));
        $validated = validator(['value' => $value], ['value' => $rules[$field]])->validate();

        $address->{$field} = $validated['value'];
        $address->save();

        Session::flash('success', __('Address updated successfully.'));

        return redirect()->route('admin.addresses.show', $address);
    }

    /**
     * Update geo fields on the address.
     */
    public function updateGeoField(Request $request, Address $address): RedirectResponse
    {
        $validated = $request->validate([
            'country_iso' => ['nullable', 'string', 'size:2'],
            'admin1_code' => ['nullable', 'string', 'max:20'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'locality' => ['nullable', 'string', 'max:255'],
        ]);

        $countryIso = $this->normalizeNullableString($validated['country_iso'] ?? null);
        $countryIso = $countryIso !== null ? strtoupper($countryIso) : null;
        $admin1Code = $this->normalizeNullableString($validated['admin1_code'] ?? null);
        $postcode = $this->normalizeNullableString($validated['postcode'] ?? null);
        $locality = $this->normalizeNullableString($validated['locality'] ?? null);

        if ($countryIso !== null && $postcode !== null && ($admin1Code === null || $locality === null)) {
            $this->ensurePostcodesImported($countryIso);
            $result = $this->lookupLocalitiesByPostcode($countryIso, $postcode);

            if ($result !== null) {
                if ($admin1Code === null && $result['admin1_code'] !== null) {
                    $admin1Code = $result['admin1_code'];
                }

                if ($locality === null && count($result['localities']) === 1) {
                    $locality = $result['localities'][0]['value'];
                }
            }
        }

        $address->country_iso = $countryIso;
        $address->admin1_code = $admin1Code;
        $address->postcode = $postcode;
        $address->locality = $locality;
        $address->save();

        Session::flash('success', __('Address geo fields updated successfully.'));

        return redirect()->route('admin.addresses.show', $address);
    }

    /**
     * Delete an address.
     */
    public function destroy(Request $request, Address $address): RedirectResponse
    {
        $linkedCount = DB::table('addressables')
            ->where('address_id', $address->id)
            ->count();

        if ($linkedCount > 0) {
            Session::flash('error', __('Cannot delete an address linked to :count entity(ies). Unlink it first.', ['count' => $linkedCount]));

            return redirect()->route('admin.addresses.index');
        }

        $address->delete();
        Session::flash('success', __('Address deleted successfully.'));

        return redirect()->route('admin.addresses.index');
    }

    /**
     * Build address listing query with optional search term.
     */
    private function buildAddressQuery(string $search): Builder
    {
        return Address::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('label', 'like', '%'.$search.'%')
                        ->orWhere('line1', 'like', '%'.$search.'%')
                        ->orWhere('locality', 'like', '%'.$search.'%')
                        ->orWhere('postcode', 'like', '%'.$search.'%')
                        ->orWhere('country_iso', 'like', '%'.$search.'%');
                });
            })
            ->latest();
    }

    /**
     * Validation rules for creating an address.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function storeRules(): array
    {
        return array_merge(Address::fieldRules(), [
            'country_iso' => ['nullable', 'string', 'size:2', Rule::exists(Country::class, 'iso')],
            'admin1_code' => ['nullable', 'string', 'max:20'],
            'parser_version' => ['nullable', 'string', 'max:255'],
            'parse_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'verification_status' => ['required', 'in:unverified,suggested,verified'],
        ]);
    }

    /**
     * Validation rules for address inline field updates.
     *
     * @return array<string, array<int, string>>
     */
    private function updatableFieldRules(): array
    {
        return array_merge(Address::fieldRules(), [
            'verification_status' => ['required', 'in:unverified,suggested,verified'],
        ]);
    }

    /**
     * Normalize nullable string input.
     */
    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
