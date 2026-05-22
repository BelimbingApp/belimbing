<?php

namespace App\Modules\Commerce\Plugins\Services;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannelProvider;
use App\Modules\Commerce\Plugins\Contracts\CommerceReadinessContributor;

class CommercePluginRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogPresets = [];

    /** @var array<class-string<MarketplaceChannelProvider>, class-string<MarketplaceChannelProvider>> */
    private array $marketplaceChannelProviders = [];

    /** @var array<class-string<CommerceReadinessContributor>, class-string<CommerceReadinessContributor>> */
    private array $readinessContributors = [];

    /** @var array<string, array<string, mixed>> */
    private array $workbenchPanels = [];

    /** @var array<string, array<string, mixed>> */
    private array $insightPages = [];

    /**
     * @param  array<string, mixed>  $preset
     */
    public function registerCatalogPreset(array $preset): void
    {
        $id = $this->idFrom($preset);

        if ($id !== null) {
            $this->catalogPresets[$id] = $preset;
        }
    }

    /**
     * @param  class-string<MarketplaceChannelProvider>  $provider
     */
    public function registerMarketplaceChannelProvider(string $provider): void
    {
        if (is_subclass_of($provider, MarketplaceChannelProvider::class)) {
            $this->marketplaceChannelProviders[$provider] = $provider;
        }
    }

    /** @param class-string<CommerceReadinessContributor> $contributor */
    public function registerReadinessContributor(string $contributor): void
    {
        if (is_subclass_of($contributor, CommerceReadinessContributor::class)) {
            $this->readinessContributors[$contributor] = $contributor;
        }
    }

    /**
     * @param  array<string, mixed>  $panel
     */
    public function registerWorkbenchPanel(array $panel): void
    {
        $id = $this->idFrom($panel);

        if ($id !== null) {
            $this->workbenchPanels[$id] = $panel;
        }
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function registerInsightPage(array $page): void
    {
        $id = $this->idFrom($page);

        if ($id !== null) {
            $this->insightPages[$id] = $page;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function catalogPresets(): array
    {
        return $this->catalogPresets;
    }

    /** @return list<class-string<MarketplaceChannelProvider>> */
    public function marketplaceChannelProviders(): array
    {
        return array_values($this->marketplaceChannelProviders);
    }

    /** @return list<class-string<CommerceReadinessContributor>> */
    public function readinessContributors(): array
    {
        return array_values($this->readinessContributors);
    }

    /**
     * @return list<array{id: string, label: string, description: string|null, entries: list<array{code: string, severity: string, label: string, description?: string, action?: string}>}>
     */
    public function itemReadinessPanels(Item $item): array
    {
        $panels = [];

        foreach ($this->workbenchPanels as $panel) {
            if (($panel['subject'] ?? null) !== 'commerce.inventory.item') {
                continue;
            }

            $contributorClass = $panel['readiness_contributor'] ?? null;
            if (! is_string($contributorClass) || ! in_array($contributorClass, $this->readinessContributors(), true)) {
                continue;
            }

            $contributor = app($contributorClass);
            if (! $contributor instanceof CommerceReadinessContributor) {
                continue;
            }

            $entries = $this->normalizeReadinessEntries($contributor->readinessForItem($item));
            if ($entries === []) {
                continue;
            }

            $panels[] = [
                'id' => $contributor->id(),
                'label' => (string) ($panel['label'] ?? $contributor->id()),
                'description' => isset($panel['description']) ? (string) $panel['description'] : null,
                'entries' => $entries,
            ];
        }

        return $panels;
    }

    /** @return array<string, array<string, mixed>> */
    public function workbenchPanels(): array
    {
        return $this->workbenchPanels;
    }

    /** @return array<string, array<string, mixed>> */
    public function insightPages(): array
    {
        return $this->insightPages;
    }

    /**
     * @param  array<string, mixed>  $contribution
     */
    private function idFrom(array $contribution): ?string
    {
        $id = $contribution['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{code: string, severity: string, label: string, description?: string, action?: string}>
     */
    private function normalizeReadinessEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            $code = $entry['code'] ?? null;
            $label = $entry['label'] ?? null;

            if (! is_string($code) || $code === '' || ! is_string($label) || $label === '') {
                continue;
            }

            $severity = $entry['severity'] ?? 'suggestion';
            if (! in_array($severity, ['success', 'blocker', 'warning', 'suggestion'], true)) {
                $severity = 'suggestion';
            }

            $row = [
                'code' => $code,
                'severity' => $severity,
                'label' => $label,
            ];

            if (isset($entry['description']) && is_string($entry['description']) && $entry['description'] !== '') {
                $row['description'] = $entry['description'];
            }

            if (isset($entry['action']) && is_string($entry['action']) && $entry['action'] !== '') {
                $row['action'] = $entry['action'];
            }

            $normalized[] = $row;
        }

        return $normalized;
    }
}
