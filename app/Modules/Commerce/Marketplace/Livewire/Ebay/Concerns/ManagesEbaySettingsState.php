<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

trait ManagesEbaySettingsState
{
    /**
     * Whether the current actor may open the full integration exchange record.
     */
    public function canViewExchanges(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'admin.system.outbound-exchange.list')
            ->allowed;
    }

    /**
     * @return Collection<int, AccountResource>
     */
    private function accountResources(): Collection
    {
        $marketplaceId = (string) app(EbayConfiguration::class)->forCompany($this->companyId())['marketplace_id'];

        return AccountResource::query()
            ->forCompanyChannel($this->companyId(), EbayConfiguration::CHANNEL, $marketplaceId)
            ->orderBy('kind')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, ProductTemplate>
     */
    private function productTemplates(): Collection
    {
        return ProductTemplate::query()
            ->where('company_id', $this->companyId())
            ->with('category')
            ->orderBy('name')
            ->get();
    }

    private function loadTemplateCategoryMappings(): void
    {
        $this->templateCategoryMappings = $this->productTemplates()
            ->mapWithKeys(function (ProductTemplate $template): array {
                $pluginMapping = app(CommercePluginRegistry::class)
                    ->marketplaceTemplateMappingForTemplate(EbayConfiguration::CHANNEL, $template);

                return [$template->id => [
                    'marketplace_id' => data_get($template->metadata, 'marketplace.ebay.marketplace_id') ?: ($pluginMapping['marketplace_id'] ?? $this->defaultMarketplaceId($template)),
                    'category_tree_id' => data_get($template->metadata, 'marketplace.ebay.category_tree_id') ?: ($pluginMapping['category_tree_id'] ?? null),
                    'category_id' => data_get($template->metadata, 'marketplace.ebay.category_id') ?: ($pluginMapping['category_id'] ?? null),
                ]];
            })
            ->all();
    }

    private function defaultMarketplaceId(?ProductTemplate $template): ?string
    {
        if ($template instanceof ProductTemplate) {
            $mapping = app(CommercePluginRegistry::class)
                ->marketplaceTemplateMappingForTemplate(EbayConfiguration::CHANNEL, $template);

            if (($mapping['marketplace_id'] ?? null) !== null) {
                return $mapping['marketplace_id'];
            }
        }

        return app(EbayConfiguration::class)->forCompany($this->companyId())['marketplace_id'] ?? null;
    }

    private function defaultCategoryTreeId(?ProductTemplate $template): ?string
    {
        if (! $template instanceof ProductTemplate) {
            return null;
        }

        $mapping = app(CommercePluginRegistry::class)
            ->marketplaceTemplateMappingForTemplate(EbayConfiguration::CHANNEL, $template);

        return $mapping['category_tree_id'] ?? null;
    }

    private function nullableDefault(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function companyId(): int
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            abort(403);
        }

        return $companyId;
    }
}
