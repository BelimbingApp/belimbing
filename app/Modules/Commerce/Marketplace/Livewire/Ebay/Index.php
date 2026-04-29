<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    /**
     * @var list<string>
     */
    private const array ACTIVE_LISTING_STATUSES = [
        'ACTIVE',
        'PUBLISHED',
    ];

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

    public function connect(EbayOAuthService $oauth): mixed
    {
        $this->authorizeConnectionManage();

        try {
            return redirect()->away($oauth->authorizationUrl($this->companyId()));
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return null;
        }
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

        return view('livewire.commerce.marketplace.ebay.index', [
            'config' => $configuration->forCompany($companyId),
            'token' => $token,
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
            ->with('item')
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

    /**
     * @return array{totalListings: int, linkedListings: int, unlinkedListings: int, driftedListings: int, unlistedItems: int}
     */
    private function stats(int $companyId): array
    {
        /** @var Collection<int, Listing> $listings */
        $listings = Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->with('item')
            ->get();

        return [
            'totalListings' => $listings->count(),
            'linkedListings' => $listings->whereNotNull('item_id')->count(),
            'unlinkedListings' => $listings->whereNull('item_id')->count(),
            'driftedListings' => $listings->filter(fn (Listing $listing): bool => $this->isDrifted($listing))->count(),
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
        if ($listing->item_id === null) {
            return __('Unlinked');
        }

        if ($this->isDrifted($listing)) {
            return __('Drifted');
        }

        return __('Matched');
    }

    public function reconciliationVariant(Listing $listing): string
    {
        if ($listing->item_id === null) {
            return 'warning';
        }

        if ($this->isDrifted($listing)) {
            return 'danger';
        }

        return 'success';
    }

    public function listingStatusVariant(?string $status): string
    {
        return in_array(Str::upper((string) $status), self::ACTIVE_LISTING_STATUSES, true)
            ? 'success'
            : 'default';
    }

    public function itemStatusVariant(?string $status): string
    {
        return match ($status) {
            Item::STATUS_DRAFT => 'default',
            Item::STATUS_READY => 'info',
            Item::STATUS_LISTED => 'accent',
            Item::STATUS_SOLD => 'success',
            Item::STATUS_ARCHIVED => 'default',
            default => 'default',
        };
    }

    public function formatMoney(?int $amount, ?string $currencyCode): string
    {
        if ($amount === null || $currencyCode === null || $currencyCode === '') {
            return __('n/a');
        }

        return Money::format($amount, $currencyCode);
    }

    public function isDrifted(Listing $listing): bool
    {
        $item = $listing->item;

        if ($item === null) {
            return false;
        }

        $listingActive = in_array(Str::upper((string) $listing->status), self::ACTIVE_LISTING_STATUSES, true);

        if ($listingActive && $item->status !== Item::STATUS_LISTED) {
            return true;
        }

        if (! $listingActive && $item->status === Item::STATUS_LISTED) {
            return true;
        }

        if (
            $item->target_price_amount !== null
            && $listing->price_amount !== null
            && $item->target_price_amount !== $listing->price_amount
        ) {
            return true;
        }

        return $item->currency_code !== null
            && $listing->currency_code !== null
            && $item->currency_code !== $listing->currency_code;
    }

    private function companyId(): int
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            abort(403);
        }

        return $companyId;
    }

    private function authorizeConnectionManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.manage',
        );
    }

    private function authorizeSyncRun(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.execute',
        );
    }
}
