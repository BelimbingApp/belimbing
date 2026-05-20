<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Livewire\SettingsForm;
use App\Modules\Commerce\Marketplace\Ebay\EbayAccountSetupImporter;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayConnectionTester;
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

    public function mount(SettingsService $settings): void
    {
        parent::mount($settings);

        $this->loadConnectionTest($settings);
        $this->loadAccountSetupDefaults($settings);
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
