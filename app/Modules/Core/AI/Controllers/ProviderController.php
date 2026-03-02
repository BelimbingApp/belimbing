<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Controllers;

use App\Modules\Core\AI\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class ProviderController
{
    /**
     * Show providers list.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $companyId = $this->companyId($request);

        $providers = AiProvider::query()
            ->forCompany($companyId)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('display_name', 'like', '%'.$search.'%')
                        ->orWhere('base_url', 'like', '%'.$search.'%');
                });
            })
            ->withCount('models')
            ->orderBy('display_name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.ai.providers.index', compact('providers', 'search'));
    }

    /**
     * Show provider create form.
     */
    public function create(): View
    {
        return view('admin.ai.providers.create');
    }

    /**
     * Store a provider.
     */
    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'max:2048'],
            'api_key' => ['required', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $provider = AiProvider::query()->create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'display_name' => $validated['display_name'],
            'base_url' => $validated['base_url'],
            'api_key' => $validated['api_key'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'created_by' => $request->user()?->employee?->id,
        ]);

        Session::flash('success', __('Provider created successfully.'));

        return redirect()->route('admin.ai.providers.show', $provider);
    }

    /**
     * Show provider details.
     */
    public function show(Request $request, AiProvider $provider): View
    {
        abort_unless($provider->company_id === $this->companyId($request), 404);
        $provider->load(['models' => fn ($query) => $query->orderBy('display_name')]);

        return view('admin.ai.providers.show', compact('provider'));
    }

    /**
     * Update provider.
     */
    public function update(Request $request, AiProvider $provider): RedirectResponse
    {
        abort_unless($provider->company_id === $this->companyId($request), 404);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'max:2048'],
            'api_key' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $provider->display_name = $validated['display_name'];
        $provider->base_url = $validated['base_url'];
        if (($validated['api_key'] ?? '') !== '') {
            $provider->api_key = $validated['api_key'];
        }
        $provider->is_active = (bool) ($validated['is_active'] ?? false);
        $provider->save();

        Session::flash('success', __('Provider updated successfully.'));

        return redirect()->route('admin.ai.providers.show', $provider);
    }

    /**
     * Delete provider.
     */
    public function destroy(Request $request, AiProvider $provider): RedirectResponse
    {
        abort_unless($provider->company_id === $this->companyId($request), 404);
        $provider->models()->delete();
        $provider->delete();

        Session::flash('success', __('Provider deleted successfully.'));

        return redirect()->route('admin.ai.providers.index');
    }

    /**
     * Resolve current company ID.
     */
    private function companyId(Request $request): int
    {
        return (int) ($request->user()?->employee?->company_id ?? 0);
    }
}
