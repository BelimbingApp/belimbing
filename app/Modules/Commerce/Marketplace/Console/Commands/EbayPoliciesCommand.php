<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Console\Commands;

use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayBusinessPolicy;
use App\Modules\Commerce\Marketplace\Ebay\EbayPoliciesService;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'commerce:marketplace:ebay:policies')]
class EbayPoliciesCommand extends Command
{
    protected $description = 'List eBay payment, fulfillment, and return policies for one or all companies';

    protected $signature = 'commerce:marketplace:ebay:policies
        {--company-id= : Company ID to query. Omit to query every company.}
        {--kind= : Limit to one kind: payment, fulfillment, or return.}';

    public function handle(EbayPoliciesService $policies): int
    {
        $companyIds = $this->companyIds();
        $kind = $this->option('kind');
        $exitCode = self::SUCCESS;

        foreach ($companyIds as $companyId) {
            $this->components->info("Company {$companyId}:");

            try {
                if ($kind === null || $kind === EbayBusinessPolicy::KIND_PAYMENT) {
                    $this->renderTable('Payment policies', $policies->pullPaymentPolicies($companyId));
                }
                if ($kind === null || $kind === EbayBusinessPolicy::KIND_FULFILLMENT) {
                    $this->renderTable('Fulfillment policies', $policies->pullFulfillmentPolicies($companyId));
                }
                if ($kind === null || $kind === EbayBusinessPolicy::KIND_RETURN) {
                    $this->renderTable('Return policies', $policies->pullReturnPolicies($companyId));
                }
            } catch (Throwable $exception) {
                $exitCode = self::FAILURE;
                $this->components->error("Company {$companyId}: {$exception->getMessage()}");
            }
        }

        return $exitCode;
    }

    /**
     * @param  Collection<int, EbayBusinessPolicy>  $policies
     */
    private function renderTable(string $heading, Collection $policies): void
    {
        $this->line("  <fg=cyan>{$heading}</> (".$policies->count().')');

        if ($policies->isEmpty()) {
            $this->components->warn('  None defined on this account; create one in eBay Seller Hub before publishing.');

            return;
        }

        $this->table(
            ['ID', 'Name', 'Marketplace', 'Description'],
            $policies->map(fn (EbayBusinessPolicy $policy): array => [
                $policy->id,
                $policy->name,
                $policy->marketplaceId,
                $this->truncate($policy->description),
            ])->all(),
        );
    }

    private function truncate(?string $value, int $max = 60): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
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
