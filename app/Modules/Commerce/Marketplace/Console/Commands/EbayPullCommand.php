<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Console\Commands;

use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'commerce:marketplace:ebay:pull')]
class EbayPullCommand extends Command
{
    protected $description = 'Pull eBay marketplace listings and orders for one or all companies';

    protected $signature = 'commerce:marketplace:ebay:pull
        {--company-id= : Company ID to pull for. Omit to pull for every company.}
        {--orders : Also pull and materialize eBay orders into Commerce Sales.}';

    public function handle(MarketplaceChannelRegistry $channels): int
    {
        $companyIds = $this->companyIds();
        $exitCode = self::SUCCESS;
        $channel = $channels->channel(EbayConfiguration::CHANNEL);

        foreach ($companyIds as $companyId) {
            try {
                $result = $channel->pullListings($companyId);
                $this->components->info("Company {$companyId}: pulled {$result->fetched} eBay listing(s), linked {$result->linked} by SKU.");

                if ($this->option('orders')) {
                    $orders = $channel->pullOrders($companyId);
                    $this->components->info("Company {$companyId}: pulled {$orders->fetched} eBay order(s), linked {$orders->linked} line(s) to inventory.");

                    foreach ($orders->warnings as $warning) {
                        $this->components->warn($warning);
                    }
                }
            } catch (Throwable $exception) {
                $exitCode = self::FAILURE;
                $this->components->error("Company {$companyId}: {$exception->getMessage()}");
            }
        }

        return $exitCode;
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
