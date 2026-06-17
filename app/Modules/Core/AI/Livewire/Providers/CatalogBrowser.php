<?php

//
// Provider catalog browser — the "Add a Provider" discovery section.
//
// Split out of the unified Providers page as a #[Lazy] child: the static
// models.dev catalog is ~100 providers, and rendering every row eagerly made
// the parent page's initial HTML ~540 KB. As a lazy island it streams in after
// first paint, so the connected-providers management view paints immediately.
// See docs/plans/performance-page-rendering.md (Phase 4 — lazy secondary sections).

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Livewire\Concerns\FormatsDisplayValues;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviderHelp;
use App\Modules\Core\AI\Models\AiProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class CatalogBrowser extends Component
{
    use FormatsDisplayValues;
    use ManagesProviderHelp;

    /** Which catalog provider row is expanded to show model details. */
    public ?string $expandedCatalogProvider = null;

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

    public function render(): View
    {
        $catalogService = app(ModelCatalogService::class);
        $companyId = $this->getCompanyId();

        $connectedNames = $companyId !== null
            ? AiProvider::query()->forCompany($companyId)->llm()->pluck('name')->all()
            : [];

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

        return view('livewire.admin.ai.providers.catalog-browser', [
            'catalog' => $catalog->all(),
            'categoryOptions' => $catalog->pluck('category')->flatten()->unique()->sort()->values()->all(),
            'regionOptions' => $catalog->pluck('region')->flatten()->unique()->sort()->values()->all(),
        ]);
    }

    public function placeholder(): View
    {
        return view('livewire.admin.ai.providers.catalog-browser-placeholder');
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
        $user = Auth::user();

        return $user !== null && method_exists($user, 'getCompanyId')
            ? $user->getCompanyId()
            : null;
    }
}
