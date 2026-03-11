<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Providers\Help\ProviderHelpRegistry;
use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Models\AiProvider;
use Livewire\Component;

class Catalog extends Component
{
    /** @var string|null Which catalog provider row is expanded */
    public ?string $expandedCatalogProvider = null;

    /** @var string|null Help panel state */
    public ?string $helpProviderKey = null;

    /** @var string|null Help panel auth type */
    public ?string $helpProviderAuthType = null;

    /**
     * Toggle expansion of a provider row in the catalog view.
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

    /**
     * Open the provider help panel (toggle behavior).
     *
     * @param  string  $providerKey  Provider key to show help for
     * @param  string  $authType  Auth type context for help content
     */
    public function openProviderHelp(string $providerKey, string $authType = 'api_key'): void
    {
        if ($this->helpProviderKey === $providerKey) {
            $this->helpProviderKey = null;
            $this->helpProviderAuthType = null;

            return;
        }

        $this->helpProviderKey = $providerKey;
        $this->helpProviderAuthType = $authType;
    }

    /**
     * Close the provider help panel.
     */
    public function closeProviderHelp(): void
    {
        $this->helpProviderKey = null;
        $this->helpProviderAuthType = null;
    }

    /**
     * Return structured help content for the currently open help panel.
     *
     * @return array{setup_steps: list<string>, troubleshooting_tips: list<string>, documentation_url: string|null, connection_error_advice: string}|null
     */
    public function activeProviderHelp(): ?array
    {
        if ($this->helpProviderKey === null) {
            return null;
        }

        $registry = app(ProviderHelpRegistry::class);
        $help = $registry->get($this->helpProviderKey, $this->helpProviderAuthType);

        return [
            'setup_steps' => $help->setupSteps(),
            'troubleshooting_tips' => $help->troubleshootingTips(),
            'documentation_url' => $help->documentationUrl(),
            'connection_error_advice' => $help->connectionErrorAdvice(),
        ];
    }

    /**
     * Navigate to the connections page (cancel catalog browsing).
     */
    public function cancelWizard(): void
    {
        $this->redirectRoute('admin.ai.providers.connections', navigate: true);
    }

    /**
     * Format a cost value for display (2 decimal places).
     *
     * @param  string|null  $cost  Raw cost value
     */
    public function formatCost(?string $cost): string
    {
        if ($cost === null || $cost === '') {
            return '—';
        }

        return '$'.number_format((float) $cost, 2);
    }

    /**
     * Format a token count for display (e.g. 200000 → "200K", 1048576 → "1M").
     *
     * @param  int|null  $count  Raw token count
     */
    public function formatTokenCount(?int $count): string
    {
        if ($count === null) {
            return '—';
        }

        if ($count >= 1000000) {
            $value = $count / 1000000;

            return rtrim(rtrim(number_format($value, 1), '0'), '.').'M';
        }

        if ($count >= 1000) {
            $value = $count / 1000;

            return rtrim(rtrim(number_format($value, 1), '0'), '.').'K';
        }

        return (string) $count;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $allProviders = app(ModelCatalogService::class)->getProviders();

        $companyId = auth()->user()?->employee?->company_id;
        $connectedNames = $companyId
            ? AiProvider::query()->forCompany((int) $companyId)->pluck('name')->all()
            : [];

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

        return view('livewire.ai.providers.catalog', [
            'catalog' => $catalog->all(),
            'categoryOptions' => $catalog->pluck('category')->flatten()->unique()->sort()->values()->all(),
            'regionOptions' => $catalog->pluck('region')->flatten()->unique()->sort()->values()->all(),
            'hasProviders' => $connectedNames !== [],
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
}
