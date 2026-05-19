<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Livewire\SettingsForm;
use App\Modules\Commerce\Marketplace\Ebay\EbayConnectionTester;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
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

    public function mount(SettingsService $settings): void
    {
        parent::mount($settings);

        $this->loadConnectionTest($settings);
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

    private function companyId(): int
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            abort(403);
        }

        return $companyId;
    }
}
