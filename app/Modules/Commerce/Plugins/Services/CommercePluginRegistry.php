<?php

namespace App\Modules\Commerce\Plugins\Services;

use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannelProvider;

class CommercePluginRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogPresets = [];

    /** @var array<class-string<MarketplaceChannelProvider>, class-string<MarketplaceChannelProvider>> */
    private array $marketplaceChannelProviders = [];

    /** @var array<string, class-string> */
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

    /**
     * @param  class-string  $contributor
     */
    public function registerReadinessContributor(string $contributor): void
    {
        $this->readinessContributors[$contributor] = $contributor;
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

    /** @return list<class-string> */
    public function readinessContributors(): array
    {
        return array_values($this->readinessContributors);
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
}
