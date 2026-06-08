<?php

namespace App\Modules\Commerce\Marketplace\Console\Commands;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayAccountSetupImporter;
use App\Modules\Commerce\Marketplace\Ebay\EbayLocationsService;
use App\Modules\Commerce\Marketplace\Ebay\EbayPoliciesService;
use App\Modules\Commerce\Marketplace\Ebay\EbayProgramService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Makes an eBay seller account ready to publish, idempotently.
 *
 * This is the bootstrap counterpart to the read-only `ebay:policies`/`ebay:pull`
 * commands. It opts in to Business Policies, optionally creates default policies
 * (sandbox/test bootstrap — real sellers manage policies in eBay Seller Hub),
 * ensures a merchant location, imports the account resources, and saves the
 * default policy/location selections the publish path requires.
 */
#[AsCommand(name: 'commerce:marketplace:ebay:account:setup')]
class EbayAccountSetupCommand extends Command
{
    protected $description = 'Prepare an eBay seller account to publish: opt in to Business Policies, ensure a merchant location, and save default policy/location selections.';

    protected $signature = 'commerce:marketplace:ebay:account:setup
        {--company-id= : Company ID to set up. Required.}
        {--create-policies : Create default policies when the account has none (sandbox/test bootstrap). Real sellers manage policies in eBay Seller Hub.}
        {--location-key=warehouse : Merchant location key to ensure.}
        {--location-country=US : Merchant location country code.}
        {--location-state=CA : Merchant location state/province.}
        {--location-city=Los Angeles : Merchant location city.}
        {--location-postal=90001 : Merchant location postal code.}';

    public function handle(
        EbayProgramService $programs,
        EbayPoliciesService $policies,
        EbayLocationsService $locations,
        EbayAccountSetupImporter $importer,
        SettingsService $settings,
    ): int {
        $companyId = $this->option('company-id');

        if (! is_numeric($companyId)) {
            $this->components->error('A numeric --company-id is required.');

            return self::FAILURE;
        }

        $companyId = (int) $companyId;
        $scope = Scope::company($companyId);

        try {
            $optedInNow = $programs->ensureOptedIn($companyId, EbayProgramService::PROGRAM_SELLING_POLICY_MANAGEMENT);
            $this->components->info($optedInNow
                ? 'Opted in to Business Policies (SELLING_POLICY_MANAGEMENT).'
                : 'Already opted in to Business Policies.');

            $policyIds = $this->resolvePolicies($policies, $companyId);

            $locationKey = $locations->ensureLocation(
                $companyId,
                (string) $this->option('location-key'),
                'Default Warehouse',
                [
                    'country' => (string) $this->option('location-country'),
                    'stateOrProvince' => (string) $this->option('location-state'),
                    'city' => (string) $this->option('location-city'),
                    'postalCode' => (string) $this->option('location-postal'),
                ],
            );
            $this->components->info("Merchant location ready: {$locationKey}.");

            $importer->import($companyId);

            $this->saveDefault($settings, $scope, 'marketplace.ebay.default_payment_policy_id', $policyIds['payment'] ?? null, 'payment policy');
            $this->saveDefault($settings, $scope, 'marketplace.ebay.default_return_policy_id', $policyIds['return'] ?? null, 'return policy');
            $this->saveDefault($settings, $scope, 'marketplace.ebay.default_fulfillment_policy_id', $policyIds['fulfillment'] ?? null, 'fulfillment policy');
            $settings->set('marketplace.ebay.default_merchant_location_key', $locationKey, $scope);
            $this->components->info("Saved default merchant location: {$locationKey}.");
        } catch (Throwable $exception) {
            $this->components->error("Company {$companyId}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{payment?: string, fulfillment?: string, return?: string}
     */
    private function resolvePolicies(EbayPoliciesService $policies, int $companyId): array
    {
        if ($this->option('create-policies')) {
            $ids = $policies->ensureDefaultPolicies($companyId);
            $this->components->info('Ensured default payment, fulfillment, and return policies.');

            return $ids;
        }

        return [
            'payment' => $policies->pullPaymentPolicies($companyId)->first()?->id,
            'fulfillment' => $policies->pullFulfillmentPolicies($companyId)->first()?->id,
            'return' => $policies->pullReturnPolicies($companyId)->first()?->id,
        ];
    }

    private function saveDefault(SettingsService $settings, Scope $scope, string $key, ?string $value, string $label): void
    {
        if ($value === null || $value === '') {
            $this->components->warn("No {$label} found; create one in eBay Seller Hub (or re-run with --create-policies) and select it in eBay settings.");

            return;
        }

        $settings->set($key, $value, $scope);
        $this->components->info("Saved default {$label}: {$value}.");
    }
}
