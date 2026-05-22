<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Livewire\SettingsForm;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Marketplace\Ebay\EbayAccountSetupImporter;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayConnectionTester;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

class Settings extends SettingsForm
{
    /**
     * @var array{status: string|null, message: string|null, tested_at: string|null, http_status: string|null, exchange_id: string|null}
     */
    public array $connectionTest = [
        'status' => null,
        'message' => null,
        'tested_at' => null,
        'http_status' => null,
        'exchange_id' => null,
    ];

    public ?string $defaultPaymentPolicyId = null;

    public ?string $defaultFulfillmentPolicyId = null;

    public ?string $defaultReturnPolicyId = null;

    public ?string $defaultMerchantLocationKey = null;

    /**
     * @var array<int, array{marketplace_id: string|null, category_tree_id: string|null, category_id: string|null}>
     */
    public array $templateCategoryMappings = [];

    public function mount(SettingsService $settings): void
    {
        parent::mount($settings);

        $this->loadConnectionTest($settings);
        $this->loadAccountSetupDefaults($settings);
        $this->loadTemplateCategoryMappings();
    }

    public function testConnection(EbayConnectionTester $tester): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        $this->connectionTest = $tester->test($this->companyId())->toArray();
    }

    public function connect(EbayOAuthService $oauth): mixed
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        try {
            session(['marketplace.ebay.oauth_return_route' => 'commerce.marketplace.ebay.settings']);

            return redirect()->away($oauth->authorizationUrl($this->companyId()));
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return null;
        }
    }

    public function importAccountSetup(EbayAccountSetupImporter $importer): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        try {
            $result = $importer->import($this->companyId());

            session()->flash('success', __('Imported :count eBay setup choices.', ['count' => $result->total()]));
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function saveAccountSetupDefaults(SettingsService $settings): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        $scope = Scope::company($this->companyId());

        $settings->set('marketplace.ebay.default_payment_policy_id', $this->nullableDefault($this->defaultPaymentPolicyId), $scope);
        $settings->set('marketplace.ebay.default_fulfillment_policy_id', $this->nullableDefault($this->defaultFulfillmentPolicyId), $scope);
        $settings->set('marketplace.ebay.default_return_policy_id', $this->nullableDefault($this->defaultReturnPolicyId), $scope);
        $settings->set('marketplace.ebay.default_merchant_location_key', $this->nullableDefault($this->defaultMerchantLocationKey), $scope);

        session()->flash('success', __('eBay setup defaults saved.'));
    }

    public function saveTemplateCategoryMappings(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        $templates = ProductTemplate::query()
            ->where('company_id', $this->companyId())
            ->whereIn('id', array_keys($this->templateCategoryMappings))
            ->get();

        foreach ($templates as $template) {
            $mapping = $this->templateCategoryMappings[$template->id] ?? [];
            $metadata = $template->metadata ?? [];
            data_set($metadata, 'marketplace.ebay.marketplace_id', $this->nullableDefault($mapping['marketplace_id'] ?? null));
            data_set($metadata, 'marketplace.ebay.category_tree_id', $this->nullableDefault($mapping['category_tree_id'] ?? null));
            data_set($metadata, 'marketplace.ebay.category_id', $this->nullableDefault($mapping['category_id'] ?? null));

            $template->metadata = $metadata;
            $template->save();
        }

        $this->loadTemplateCategoryMappings();
        session()->flash('success', __('eBay category mappings saved.'));
    }

    public function refreshMappedCategoryMetadata(EbayMetadataService $metadata): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        $mappings = collect($this->templateCategoryMappings)
            ->map(fn (array $mapping): array => [
                'marketplace_id' => $this->nullableDefault($mapping['marketplace_id'] ?? null) ?: 'EBAY_MOTORS_US',
                'category_tree_id' => $this->nullableDefault($mapping['category_tree_id'] ?? null) ?: '100',
                'category_id' => $this->nullableDefault($mapping['category_id'] ?? null),
            ])
            ->filter(fn (array $mapping): bool => $mapping['category_id'] !== null)
            ->unique(fn (array $mapping): string => implode(':', $mapping))
            ->values();

        if ($mappings->isEmpty()) {
            session()->flash('error', __('Add at least one eBay category ID before refreshing metadata.'));

            return;
        }

        try {
            $companyId = $this->companyId();

            foreach ($mappings as $mapping) {
                $categoryId = (string) $mapping['category_id'];
                $marketplaceId = (string) $mapping['marketplace_id'];
                $categoryTreeId = (string) $mapping['category_tree_id'];

                $metadata->categoryTree($companyId, $marketplaceId, $categoryTreeId, forceRefresh: true);
                $metadata->categorySubtree($companyId, $marketplaceId, $categoryTreeId, $categoryId, forceRefresh: true);
                $metadata->categoryAspects($companyId, $marketplaceId, $categoryTreeId, $categoryId, forceRefresh: true);
                $metadata->compatibilityProperties($companyId, $marketplaceId, $categoryTreeId, $categoryId, forceRefresh: true);
                $metadata->automotivePartsCompatibilityPolicies($companyId, $marketplaceId, [$categoryId], forceRefresh: true);
                $metadata->itemConditionPolicies($companyId, $marketplaceId, [$categoryId], forceRefresh: true);
            }

            session()->flash('success', trans_choice('Refreshed eBay metadata for :count mapped category.|Refreshed eBay metadata for :count mapped categories.', $mappings->count(), ['count' => $mappings->count()]));
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function connectButtonLabel(): string
    {
        return app(EbayOAuthService::class)->tokenForCompany($this->companyId()) === null
            ? __('Connect eBay')
            : __('Reconnect eBay');
    }

    public function connectionTestBadgeVariant(): string
    {
        return match ($this->connectionTest['status']) {
            EbayConnectionTester::STATUS_HEALTHY => 'success',
            EbayConnectionTester::STATUS_ATTENTION => 'warning',
            EbayConnectionTester::STATUS_FAILED => 'danger',
            default => 'default',
        };
    }

    public function connectionTestAlertVariant(): string
    {
        return match ($this->connectionTest['status']) {
            EbayConnectionTester::STATUS_HEALTHY => 'success',
            EbayConnectionTester::STATUS_ATTENTION => 'warning',
            EbayConnectionTester::STATUS_FAILED => 'danger',
            default => 'info',
        };
    }

    public function render(): View
    {
        $group = $this->groupConfig();

        return view('livewire.commerce.marketplace.ebay.settings', [
            'groupId' => $this->group(),
            'group' => $group,
            'pageTitle' => __(':label Settings', ['label' => $group['label'] ?? __('Module')]),
            'pageSubtitle' => __($group['description'] ?? 'Operator-editable module settings stored in base_settings.'),
            'accountResources' => $this->accountResources(),
            'productTemplates' => $this->productTemplates(),
        ]);
    }

    protected function group(): string
    {
        return 'marketplace_ebay';
    }

    private function loadConnectionTest(SettingsService $settings): void
    {
        $scope = Scope::company($this->companyId());

        $this->connectionTest = [
            'status' => $settings->get('marketplace.ebay.connection_test_status', null, $scope),
            'message' => $settings->get('marketplace.ebay.connection_test_message', null, $scope),
            'tested_at' => $settings->get('marketplace.ebay.connection_tested_at', null, $scope),
            'http_status' => $settings->get('marketplace.ebay.connection_test_http_status', null, $scope),
            'exchange_id' => $settings->get('marketplace.ebay.connection_test_exchange_id', null, $scope),
        ];
    }

    private function loadAccountSetupDefaults(SettingsService $settings): void
    {
        $scope = Scope::company($this->companyId());

        $this->defaultPaymentPolicyId = $this->nullableDefault($settings->get('marketplace.ebay.default_payment_policy_id', null, $scope));
        $this->defaultFulfillmentPolicyId = $this->nullableDefault($settings->get('marketplace.ebay.default_fulfillment_policy_id', null, $scope));
        $this->defaultReturnPolicyId = $this->nullableDefault($settings->get('marketplace.ebay.default_return_policy_id', null, $scope));
        $this->defaultMerchantLocationKey = $this->nullableDefault($settings->get('marketplace.ebay.default_merchant_location_key', null, $scope));
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
                return [$template->id => [
                    'marketplace_id' => data_get($template->metadata, 'marketplace.ebay.marketplace_id') ?: 'EBAY_MOTORS_US',
                    'category_tree_id' => data_get($template->metadata, 'marketplace.ebay.category_tree_id') ?: '100',
                    'category_id' => data_get($template->metadata, 'marketplace.ebay.category_id'),
                ]];
            })
            ->all();
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
