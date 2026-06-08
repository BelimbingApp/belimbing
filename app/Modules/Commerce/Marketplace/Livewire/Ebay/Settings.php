<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Livewire\SettingsForm;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Marketplace\Ebay\Diagnostics\EbayDiagnosticProbes;
use App\Modules\Commerce\Marketplace\Ebay\EbayAccountSetupImporter;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayDiagnosticsService;
use App\Modules\Commerce\Marketplace\Ebay\EbayLocationsService;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Ebay\EbayPoliciesService;
use App\Modules\Commerce\Marketplace\Ebay\EbayProgramService;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use App\Modules\Core\Geonames\Concerns\HasGeonamesLookups;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

class Settings extends SettingsForm
{
    use HasGeonamesLookups;

    /**
     * Last persisted diagnostics result (see EbayDiagnosticsResult::toArray()).
     *
     * @var array<string, mixed>
     */
    public array $diagnostics = [];

    public string $diagnosticProbeKey = EbayDiagnosticProbes::DEFAULT_KEY;

    public ?string $defaultPaymentPolicyId = null;

    public ?string $defaultFulfillmentPolicyId = null;

    public ?string $defaultReturnPolicyId = null;

    public ?string $defaultMerchantLocationKey = null;

    /**
     * Last-known Business Policies opt-in state (true once confirmed; null when
     * never checked). Cached in settings so the page can show status without an
     * API call on every render.
     */
    public ?bool $businessPoliciesOptedIn = null;

    /**
     * Per-action inline feedback shown next to each Account-setup button, keyed
     * by action (optin|location|policies).
     *
     * @var array<string, array{variant: string, message: string}>
     */
    public array $setupFeedback = [];

    public string $newLocationKey = 'warehouse';

    public string $newLocationCity = '';

    public string $newLocationState = '';

    public string $newLocationPostal = '';

    public string $newLocationCountry = 'US';

    /**
     * State/province suggestions for the chosen location country, sourced from
     * GeoNames admin1 data. The field stays editable so countries without
     * admin1 coverage still accept a typed value.
     *
     * @var array<int, array{value: string, label: string}>
     */
    public array $newLocationStateOptions = [];

    /**
     * @var array<int, array{marketplace_id: string|null, category_tree_id: string|null, category_id: string|null}>
     */
    public array $templateCategoryMappings = [];

    public function mount(SettingsService $settings): void
    {
        parent::mount($settings);

        $this->loadDiagnostics($settings);
        $this->loadAccountSetupDefaults($settings);
        $this->loadTemplateCategoryMappings();

        $optedIn = $settings->get('marketplace.ebay.business_policies_opted_in', null, Scope::company($this->companyId()));
        $this->businessPoliciesOptedIn = is_bool($optedIn) ? $optedIn : null;

        $this->newLocationStateOptions = $this->locationStateOptions($this->newLocationCountry);
    }

    /**
     * Refresh the state/province suggestions whenever the location country
     * changes, and clear the now-mismatched state so the operator re-picks.
     */
    public function updatedNewLocationCountry(mixed $value): void
    {
        $this->newLocationState = '';
        $this->newLocationStateOptions = $this->locationStateOptions(is_string($value) ? $value : null);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function locationStateOptions(?string $countryIso): array
    {
        if (! is_string($countryIso) || trim($countryIso) === '') {
            return [];
        }

        // Reuse the shared GeoNames lookup; eBay's stateOrProvince wants the bare
        // subdivision code (e.g. "CA"), so request the subdivision-code value.
        return $this->loadAdmin1ForCountry($countryIso, subdivisionCode: true);
    }

    public function runDiagnostics(EbayDiagnosticsService $diagnostics): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        $this->diagnostics = $diagnostics->run($this->companyId(), $this->diagnosticProbeKey)->toArray();
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

    /**
     * Opt the seller in to the eBay Business Policies program. Required before
     * payment/fulfillment/return policies can be read or created (eBay error
     * 20403 until this is done).
     */
    public function optInToBusinessPolicies(EbayProgramService $programs, EbayAccountSetupImporter $importer, SettingsService $settings): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        try {
            $optedInNow = $programs->ensureOptedIn($this->companyId(), EbayProgramService::PROGRAM_SELLING_POLICY_MANAGEMENT);
            $importer->import($this->companyId());

            $settings->set('marketplace.ebay.business_policies_opted_in', true, Scope::company($this->companyId()));
            $this->businessPoliciesOptedIn = true;

            $this->setupFeedback['optin'] = ['variant' => 'success', 'message' => $optedInNow
                ? __('Done. Business Policies are now switched on for your eBay account. Next, create your shipping/payment/return policies (or "Create starter policies" below in the sandbox).')
                : __('Already on. Your eBay account was already opted in — nothing was changed. You can move on to creating a location and policies.')];
        } catch (Throwable $exception) {
            $this->setupFeedback['optin'] = ['variant' => 'error', 'message' => __('Could not switch on Business Policies: :message', ['message' => $exception->getMessage()])];
        }
    }

    /**
     * Create an eBay Inventory API merchant location and set it as the default.
     * A location is required to publish an offer and is not something sellers
     * usually configure in Seller Hub, so it is created here.
     */
    public function createMerchantLocation(EbayLocationsService $locations, EbayAccountSetupImporter $importer, SettingsService $settings): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        $validated = $this->validate([
            'newLocationKey' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'newLocationCity' => ['required', 'string', 'max:100'],
            'newLocationState' => ['required', 'string', 'max:100'],
            'newLocationPostal' => ['required', 'string', 'max:20'],
            'newLocationCountry' => ['required', 'string', 'size:2'],
        ]);

        try {
            $key = $validated['newLocationKey'];
            $action = $locations->saveLocation(
                $this->companyId(),
                $key,
                $key,
                [
                    'country' => strtoupper($validated['newLocationCountry']),
                    'stateOrProvince' => $validated['newLocationState'],
                    'city' => $validated['newLocationCity'],
                    'postalCode' => $validated['newLocationPostal'],
                ],
            );

            $importer->import($this->companyId());
            $settings->set('marketplace.ebay.default_merchant_location_key', $key, Scope::company($this->companyId()));
            $this->defaultMerchantLocationKey = $key;

            $this->setupFeedback['location'] = ['variant' => 'success', 'message' => $action === 'updated'
                ? __('Updated the address on eBay for ":key" (:city, :state :postal) and kept it as your default location.', [
                    'key' => $key, 'city' => $validated['newLocationCity'], 'state' => $validated['newLocationState'], 'postal' => $validated['newLocationPostal'],
                ])
                : __('Saved on eBay and set as your default location: ":key" (:city, :state :postal). It now appears under "Default merchant location" below.', [
                    'key' => $key, 'city' => $validated['newLocationCity'], 'state' => $validated['newLocationState'], 'postal' => $validated['newLocationPostal'],
                ])];
        } catch (Throwable $exception) {
            $this->setupFeedback['location'] = ['variant' => 'error', 'message' => __('Could not save the location on eBay: :message', ['message' => $exception->getMessage()])];
        }
    }

    /**
     * Create simple starter payment/return/fulfillment policies and select them
     * as defaults. Sandbox/test bootstrap only — real sellers manage policies in
     * eBay Seller Hub, so this is hidden on the live environment.
     */
    public function createStarterPolicies(EbayPoliciesService $policies, EbayAccountSetupImporter $importer, SettingsService $settings): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );

        try {
            $ids = $policies->ensureDefaultPolicies($this->companyId());
            $importer->import($this->companyId());

            $scope = Scope::company($this->companyId());
            $settings->set('marketplace.ebay.default_payment_policy_id', $ids['payment'], $scope);
            $settings->set('marketplace.ebay.default_fulfillment_policy_id', $ids['fulfillment'], $scope);
            $settings->set('marketplace.ebay.default_return_policy_id', $ids['return'], $scope);

            $this->defaultPaymentPolicyId = $ids['payment'];
            $this->defaultFulfillmentPolicyId = $ids['fulfillment'];
            $this->defaultReturnPolicyId = $ids['return'];

            $this->setupFeedback['policies'] = ['variant' => 'success', 'message' => __('Three policies are now on your eBay account and selected as your defaults: a payment policy (buyer pays immediately), a return policy (30-day returns, item replaced, you cover return shipping), and a shipping policy (USPS Priority flat $9.99, 2-day handling). To read or change the full details, open eBay Seller Hub → Business Policies.')];
        } catch (Throwable $exception) {
            $this->setupFeedback['policies'] = ['variant' => 'error', 'message' => __('Could not create starter policies: :message', ['message' => $exception->getMessage()])];
        }
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

        $templates = $this->productTemplates()->keyBy('id');

        $mappings = collect($this->templateCategoryMappings)
            ->map(fn (array $mapping, int $templateId): array => [
                'marketplace_id' => $this->nullableDefault($mapping['marketplace_id'] ?? null) ?: $this->defaultMarketplaceId($templates->get($templateId)),
                'category_tree_id' => $this->nullableDefault($mapping['category_tree_id'] ?? null) ?: $this->defaultCategoryTreeId($templates->get($templateId)),
                'category_id' => $this->nullableDefault($mapping['category_id'] ?? null),
            ])
            ->filter(fn (array $mapping): bool => $mapping['category_id'] !== null && $mapping['marketplace_id'] !== null && $mapping['category_tree_id'] !== null)
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

    public function diagnosticsBadgeVariant(): string
    {
        return match ($this->diagnostics['status'] ?? null) {
            EbayDiagnosticsService::STATUS_HEALTHY => 'success',
            EbayDiagnosticsService::STATUS_ATTENTION => 'warning',
            EbayDiagnosticsService::STATUS_FAILED => 'danger',
            default => 'default',
        };
    }

    public function diagnosticsAlertVariant(): string
    {
        return match ($this->diagnostics['status'] ?? null) {
            EbayDiagnosticsService::STATUS_HEALTHY => 'success',
            EbayDiagnosticsService::STATUS_ATTENTION => 'warning',
            EbayDiagnosticsService::STATUS_FAILED => 'danger',
            default => 'info',
        };
    }

    /**
     * Whether the current actor may open the full integration exchange record.
     */
    public function canViewExchanges(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'admin.system.outbound-exchange.list')
            ->allowed;
    }

    public function render(): View
    {
        $group = $this->groupConfig();

        return view('commerce-marketplace::livewire.commerce.marketplace.ebay.settings', [
            'groupId' => $this->group(),
            'group' => $group,
            'pageTitle' => __(':label Settings', ['label' => $group['label'] ?? __('Module')]),
            'pageSubtitle' => __($group['description'] ?? 'Operator-editable module settings stored in base_settings.'),
            'accountResources' => $this->accountResources(),
            'productTemplates' => $this->productTemplates(),
            'environment' => app(EbayConfiguration::class)->forCompany($this->companyId())['environment'] ?? null,
            'diagnosticProbes' => app(EbayDiagnosticProbes::class)->all(),
            'canViewExchanges' => $this->canViewExchanges(),
        ]);
    }

    protected function group(): string
    {
        return 'marketplace_ebay';
    }

    private function loadDiagnostics(SettingsService $settings): void
    {
        $stored = $settings->get(EbayDiagnosticsService::SETTINGS_KEY, null, Scope::company($this->companyId()));

        $this->diagnostics = is_array($stored) ? $stored : [];
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
