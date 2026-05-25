<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Integration\Models\OutboundExchange;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingAuditService;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Ebay\EbayStoreAlignmentService;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $listingFilter = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->resetPage('unlistedPage');
    }

    public function updatedListingFilter(): void
    {
        $this->resetPage();
        $this->resetPage('unlistedPage');
    }

    public function pullListings(MarketplaceChannelRegistry $channels): void
    {
        $this->authorizeSyncRun();

        try {
            $result = $channels
                ->channel(EbayConfiguration::CHANNEL)
                ->pullListings($this->companyId());
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('success', __(
            'Pulled :fetched eBay listings (:created created, :updated updated, :linked linked by SKU).',
            [
                'fetched' => $result->fetched,
                'created' => $result->created,
                'updated' => $result->updated,
                'linked' => $result->linked,
            ],
        ));
    }

    public function pullOrders(MarketplaceChannelRegistry $channels): void
    {
        $this->authorizeSyncRun();

        try {
            $result = $channels
                ->channel(EbayConfiguration::CHANNEL)
                ->pullOrders($this->companyId());
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('success', __(
            'Pulled :fetched eBay orders (:created created, :updated updated, :linked linked line items).',
            [
                'fetched' => $result->fetched,
                'created' => $result->created,
                'updated' => $result->updated,
                'linked' => $result->linked,
            ],
        ));
    }

    public function render(EbayConfiguration $configuration, EbayOAuthService $oauth): View
    {
        $companyId = $this->companyId();
        $token = $oauth->tokenForCompany($companyId);
        $dashboard = $this->storeAlignment()->dashboard($companyId);

        return view('commerce-marketplace::livewire.commerce.marketplace.ebay.index', [
            'config' => $configuration->forCompany($companyId),
            'token' => $token,
            'listings' => $this->listings($companyId),
            'unlistedItems' => $this->unlistedItems($companyId),
            'stats' => $this->stats($companyId),
            'recentExchanges' => $this->recentExchanges($companyId),
            'cleanupQueue' => $dashboard['cleanupQueue'],
            'qualitySummary' => $dashboard['qualitySummary'],
            'trustSignals' => $dashboard['trustSignals'],
            'fitmentBatchCandidates' => $dashboard['fitmentBatchCandidates'],
        ]);
    }

    /**
     * @return Collection<int, OutboundExchange>
     */
    private function recentExchanges(int $companyId): Collection
    {
        return OutboundExchange::query()
            ->where('system', EbayConfiguration::CHANNEL)
            ->where('owner_type', 'company')
            ->where('owner_id', $companyId)
            ->latest('occurred_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Listing>
     */
    private function listings(int $companyId): LengthAwarePaginator
    {
        return Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->with(['item', 'draft'])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('external_sku', 'like', '%'.$this->search.'%')
                        ->orWhere('external_listing_id', 'like', '%'.$this->search.'%')
                        ->orWhere('external_offer_id', 'like', '%'.$this->search.'%')
                        ->orWhere('title', 'like', '%'.$this->search.'%')
                        ->orWhereHas('item', function (Builder $itemQuery): void {
                            $itemQuery->where('sku', 'like', '%'.$this->search.'%')
                                ->orWhere('title', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->listingFilter === 'linked', fn (Builder $query) => $query->whereNotNull('item_id'))
            ->when($this->listingFilter === 'unlinked', fn (Builder $query) => $query->whereNull('item_id'))
            ->latest('last_synced_at')
            ->paginate(20);
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    private function unlistedItems(int $companyId): LengthAwarePaginator
    {
        return Item::query()
            ->where('company_id', $companyId)
            ->whereNotIn('status', [Item::STATUS_SOLD, Item::STATUS_ARCHIVED])
            ->whereDoesntHave('marketplaceListings', function (Builder $query): void {
                $query->where('channel', EbayConfiguration::CHANNEL);
            })
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('sku', 'like', '%'.$this->search.'%')
                        ->orWhere('title', 'like', '%'.$this->search.'%')
                        ->orWhere('notes', 'like', '%'.$this->search.'%')
                        ->orWhere('status', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'unlistedPage');
    }

    private function stats(int $companyId): array
    {
        $listings = Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->with(['item', 'draft'])
            ->get();

        return [
            ...$this->audit()->stats($listings),
            'unlistedItems' => Item::query()
                ->where('company_id', $companyId)
                ->whereNotIn('status', [Item::STATUS_SOLD, Item::STATUS_ARCHIVED])
                ->whereDoesntHave('marketplaceListings', function (Builder $query): void {
                    $query->where('channel', EbayConfiguration::CHANNEL);
                })
                ->count(),
        ];
    }

    public function reconciliationLabel(Listing $listing): string
    {
        return $this->audit()->label($listing);
    }

    public function reconciliationVariant(Listing $listing): string
    {
        return $this->audit()->variant($listing);
    }

    public function listingStatusVariant(?string $status): string
    {
        return $this->audit()->listingStatusVariant($status);
    }

    public function managementStateVariant(string $state): string
    {
        return $this->audit()->managementStateVariant($state);
    }

    public function itemStatusVariant(?string $status): string
    {
        return $this->audit()->itemStatusVariant($status);
    }

    public function formatMoney(?int $amount, ?string $currencyCode): string
    {
        return $this->audit()->formatMoney($amount, $currencyCode);
    }

    public function isDrifted(Listing $listing): bool
    {
        return in_array($this->audit()->state($listing), [
            Listing::RECONCILIATION_EXTERNALLY_CHANGED,
            Listing::RECONCILIATION_DRIFTED,
        ], true);
    }

    private function companyId(): int
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            abort(403);
        }

        return $companyId;
    }

    private function authorizeSyncRun(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.execute',
        );
    }

    private function audit(): EbayListingAuditService
    {
        return app(EbayListingAuditService::class);
    }

    private function storeAlignment(): EbayStoreAlignmentService
    {
        return app(EbayStoreAlignmentService::class);
    }
}
