<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Unified full-page component for AI provider management.
//
// Combines the former Connections (provider CRUD, model management) and
// Catalog (provider discovery/browsing) into a single page. Connected
// providers appear at the top; the full catalog sits below as a
// secondary discovery section.

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Livewire\Concerns\FormatsDisplayValues;
use App\Modules\Core\AI\Livewire\Concerns\ManagesModels;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviderHelp;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviders;
use App\Modules\Core\AI\Livewire\Concerns\ManagesSync;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Providers extends Component implements ProvidesLaraPageContext
{
    use FormatsDisplayValues;
    use ManagesModels;
    use ManagesProviderHelp;
    use ManagesProviders;
    use ManagesSync;

    /** Which connected provider row is expanded to show models. */
    public ?int $expandedProviderId = null;

    /** Which catalog provider row is expanded to show model details. */
    public ?string $expandedCatalogProvider = null;

    /**
     * Toggle expansion of a connected provider row.
     */
    public function toggleProvider(int $providerId): void
    {
        $this->expandedProviderId = $this->expandedProviderId === $providerId ? null : $providerId;
    }

    /**
     * Toggle expansion of a catalog provider row.
     */
    public function toggleCatalogProvider(string $key): void
    {
        $this->expandedCatalogProvider = $this->expandedCatalogProvider === $key ? null : $key;
    }

    /**
     * Navigate to the setup page for a single provider.
     *
     * @param  string  $key  Provider key to connect
     */
    public function connectProvider(string $key): void
    {
        $this->redirectRoute('admin.ai.providers.setup', ['providerKey' => $key], navigate: true);
    }

    public function pageContext(): PageContext
    {
        $companyId = $this->getCompanyId();
        $connectedCount = $companyId !== null
            ? AiProvider::query()->forCompany($companyId)->count()
            : 0;

        return new PageContext(
            route: 'admin.ai.providers',
            url: route('admin.ai.providers'),
            title: 'AI Providers ('.$connectedCount.' connected)',
            module: 'AI',
            resourceType: 'provider',
            visibleActions: ['Connect provider', 'Sync models', 'Browse catalog'],
        );
    }

    public function render(): View
    {
        $catalogService = app(ModelCatalogService::class);
        $companyId = $this->getCompanyId();

        // ── Connected providers (top section) ──
        $providers = collect();
        $expandedModels = collect();
        $connectedNames = [];

        if ($companyId !== null) {
            $providers = AiProvider::query()
                ->forCompany($companyId)
                ->withCount('models')
                ->orderBy('priority')
                ->orderBy('display_name')
                ->get();
            $connectedNames = AiProvider::query()->forCompany($companyId)->pluck('name')->all();

            if ($this->expandedProviderId !== null) {
                $expandedModels = AiProviderModel::query()
                    ->where('ai_provider_id', $this->expandedProviderId)
                    ->orderBy('model_id')
                    ->get();
            }
        }

        // ── Provider catalog (bottom section) ──
        $allProviders = $catalogService->getProviders();

        $catalog = collect($allProviders)
            ->map(function ($tpl, $key) use ($connectedNames) {
                $models = is_array($tpl['models'] ?? null) ? $tpl['models'] : [];

                return [
                    'key' => $key,
                    'display_name' => $tpl['display_name'] ?? $key,
                    'description' => $tpl['description'] ?? '',
                    'base_url' => $tpl['base_url'] ?? '',
                    'api_key_url' => $tpl['api_key_url'] ?? null,
                    'auth_type' => $tpl['auth_type'] ?? 'api_key',
                    'category' => $tpl['category'] ?? ['specialized'],
                    'region' => $tpl['region'] ?? ['global'],
                    'model_count' => count($models),
                    'cost_range' => $this->extractCostRange($models),
                    'models' => collect($models)->map(fn ($m, $id) => [
                        'model_id' => is_string($id) ? $id : ($m['id'] ?? ''),
                        'display_name' => $m['name'] ?? $m['id'] ?? $id,
                        'context_window' => $m['limit']['context'] ?? null,
                        'max_tokens' => $m['limit']['output'] ?? null,
                        'cost' => $m['cost'] ?? [],
                    ])->values()->all(),
                    'connected' => in_array($key, $connectedNames, true),
                ];
            })
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $templates = collect($allProviders)
            ->map(fn ($t, $key) => ['value' => $key, 'label' => $t['display_name'] ?? $key])
            ->values()
            ->all();

        return view('livewire.admin.ai.providers.providers', [
            'providers' => $providers,
            'expandedModels' => $expandedModels,
            'templateOptions' => $templates,
            'laraActivated' => Employee::laraActivationState() === true,
            'catalog' => $catalog->all(),
            'categoryOptions' => $catalog->pluck('category')->flatten()->unique()->sort()->values()->all(),
            'regionOptions' => $catalog->pluck('region')->flatten()->unique()->sort()->values()->all(),
        ]);
    }

    /**
     * Extract a min/max cost range from a provider's model list.
     *
     * Scans input and output costs across all models. Returns null when no
     * costs are available, a single float when min equals max, or an
     * associative array with 'min' and 'max' keys.
     *
     * @param  array<array-key, array<string, mixed>>  $models  Raw model data from catalog
     * @return float|array{min: float, max: float}|null
     */
    private function extractCostRange(array $models): float|array|null
    {
        $costs = [];

        foreach ($models as $m) {
            foreach (['input', 'output'] as $dim) {
                $c = $m['cost'][$dim] ?? null;
                if ($c !== null && $c !== '') {
                    $costs[] = (float) $c;
                }
            }
        }

        if ($costs === []) {
            return null;
        }

        $min = min($costs);
        $max = max($costs);

        return $min === $max ? $min : ['min' => $min, 'max' => $max];
    }

    private function getCompanyId(): ?int
    {
        $user = auth()->user();

        return $user?->employee?->company_id ? (int) $user->employee->company_id : null;
    }
}
