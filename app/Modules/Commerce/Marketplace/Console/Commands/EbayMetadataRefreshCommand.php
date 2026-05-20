<?php

namespace App\Modules\Commerce\Marketplace\Console\Commands;

use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'commerce:marketplace:ebay:metadata-refresh')]
class EbayMetadataRefreshCommand extends Command
{
    protected $description = 'Refresh cached eBay category, aspect, compatibility, and condition metadata';

    protected $signature = 'commerce:marketplace:ebay:metadata-refresh
        {--company-id= : Company ID to refresh. Omit to refresh every company.}
        {--marketplace-id= : Metadata marketplace ID, for example EBAY_MOTORS_US. Defaults to the company eBay marketplace.}
        {--category-tree-id= : eBay category tree ID, for example 100 for US eBay Motors.}
        {--category-id=* : eBay category ID to refresh. Repeat for multiple categories.}
        {--force : Force refresh even when cached metadata is still fresh.}';

    public function handle(EbayConfiguration $configuration, EbayMetadataService $metadata): int
    {
        if ($this->categoryTreeId() === null) {
            $this->components->error('Pass --category-tree-id, for example --category-tree-id=100 for US eBay Motors.');

            return self::FAILURE;
        }

        $exitCode = self::SUCCESS;
        $force = (bool) $this->option('force');

        foreach ($this->companyIds() as $companyId) {
            $marketplaceId = $this->marketplaceId($configuration, $companyId);
            $categoryTreeId = $this->categoryTreeId();

            $this->components->info("Company {$companyId}: refreshing {$marketplaceId} category tree {$categoryTreeId}.");

            try {
                $tree = $metadata->categoryTree($companyId, $marketplaceId, $categoryTreeId, $force);
                $this->line('  '.$this->summary('Category tree', $tree));

                foreach ($this->categoryIds() as $categoryId) {
                    $this->refreshCategory($metadata, $companyId, $marketplaceId, $categoryTreeId, $categoryId, $force);
                }
            } catch (Throwable $exception) {
                $exitCode = self::FAILURE;
                $this->components->error("Company {$companyId}: {$exception->getMessage()}");
            }
        }

        return $exitCode;
    }

    private function refreshCategory(
        EbayMetadataService $metadata,
        int $companyId,
        string $marketplaceId,
        string $categoryTreeId,
        string $categoryId,
        bool $force,
    ): void {
        $this->line("  Category {$categoryId}:");

        $subtree = $metadata->categorySubtree($companyId, $marketplaceId, $categoryTreeId, $categoryId, $force);
        $aspects = $metadata->categoryAspects($companyId, $marketplaceId, $categoryTreeId, $categoryId, $force);
        $compatibilityProperties = $metadata->compatibilityProperties($companyId, $marketplaceId, $categoryTreeId, $categoryId, $force);
        $compatibilityPolicies = $metadata->automotivePartsCompatibilityPolicies($companyId, $marketplaceId, [$categoryId], $force);
        $conditionPolicies = $metadata->itemConditionPolicies($companyId, $marketplaceId, [$categoryId], $force);

        $this->line('    '.$this->summary('Subtree', $subtree));
        $this->line('    '.$this->summary('Aspects', $aspects, 'aspects'));
        $this->line('    '.$this->summary('Compatibility properties', $compatibilityProperties, 'compatibilityProperties'));
        $this->line('    '.$this->summary('Compatibility policies', $compatibilityPolicies, 'automotivePartsCompatibilityPolicies'));
        $this->line('    '.$this->summary('Condition policies', $conditionPolicies, 'itemConditionPolicies'));
    }

    private function summary(string $label, MarketplaceMetadata $metadata, ?string $payloadListKey = null): string
    {
        $count = $payloadListKey === null ? null : count($metadata->payload[$payloadListKey] ?? []);
        $state = $metadata->refreshState();
        $fetched = $metadata->fetched_at->diffForHumans();

        return $count === null
            ? "{$label}: {$state}, fetched {$fetched}"
            : "{$label}: {$count} cached, {$state}, fetched {$fetched}";
    }

    private function marketplaceId(EbayConfiguration $configuration, int $companyId): string
    {
        $option = $this->option('marketplace-id');

        if (is_string($option) && trim($option) !== '') {
            return strtoupper(trim($option));
        }

        return (string) $configuration->forCompany($companyId)['marketplace_id'];
    }

    private function categoryTreeId(): ?string
    {
        $value = $this->option('category-tree-id');
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function categoryIds(): array
    {
        return collect($this->option('category-id'))
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function companyIds(): array
    {
        $companyId = $this->option('company-id');

        if ($companyId !== null && $companyId !== '') {
            return [(int) $companyId];
        }

        return Company::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
