<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingAuditService;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Pull from eBay: fetch the store's listings and recent orders into Belimbing
     * in one action (eBay is the remote, Belimbing the local working copy).
     *
     * This is fetch-style, not a blind merge: a Belimbing-managed listing that
     * changed on eBay is flagged as drifted rather than silently overwritten, so
     * local edits are never clobbered. Sending Belimbing changes the other way
     * (push) is a deliberate per-listing action, not part of this bulk pull.
     */
    public function pullFromEbay(MarketplaceChannelRegistry $channels): void
    {
        $this->authorizeSyncRun();

        try {
            $channel = $channels->channel(EbayConfiguration::CHANNEL);
            $listings = $channel->pullListings($this->companyId());
            $orders = $channel->pullOrders($this->companyId());
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('success', __(
            'Pulled from eBay — :listingsFetched listings (:listingsCreated new, :listingsUpdated updated) and :ordersFetched orders (:ordersCreated new).',
            [
                'listingsFetched' => $listings->fetched,
                'listingsCreated' => $listings->created,
                'listingsUpdated' => $listings->updated,
                'ordersFetched' => $orders->fetched,
                'ordersCreated' => $orders->created,
            ],
        ));
    }

    public function render(EbayConfiguration $configuration, EbayOAuthService $oauth): View
    {
        $companyId = $this->companyId();

        return view('commerce-marketplace::livewire.commerce.marketplace.ebay.index', [
            'config' => $configuration->forCompany($companyId),
            'token' => $oauth->tokenForCompany($companyId),
            'listings' => $this->listings($companyId),
            'unlistedItems' => $this->unlistedItems($companyId),
            'stats' => $this->stats($companyId),
        ]);
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
        $listingBaseQuery = Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL);

        $totalListings = (clone $listingBaseQuery)->count();
        $unlinkedListings = (clone $listingBaseQuery)->whereNull('item_id')->count();

        return [
            'totalListings' => $totalListings,
            'linkedListings' => $totalListings - $unlinkedListings,
            'unlinkedListings' => $unlinkedListings,
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
}
