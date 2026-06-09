<?php

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingAuditService;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannel;
use App\Modules\Commerce\Marketplace\Ebay\EbayOAuthService;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Services\MarketplaceListingPushService;
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

    // The table mirrors eBay's active set by default; ended listings are hidden
    // unless asked for.
    public bool $includeEnded = false;

    // Per-listing quick edit-and-push modal.
    public bool $showListingModal = false;

    public ?int $modalListingId = null;

    public string $modalTitle = '';

    public string $modalPrice = '';

    // Import existing eBay listings: a Trading-API (GetMyeBaySelling) picker.
    public bool $showImportModal = false;

    public bool $listingsLoaded = false;

    /**
     * @var list<array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, listing_type: string|null, view_url: string|null}>
     */
    public array $sellerListings = [];

    /**
     * @var list<string>
     */
    public array $selectedImportIds = [];

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

    public function updatedIncludeEnded(): void
    {
        $this->resetPage();
    }

    /**
     * Open the quick edit-and-push modal for one linked, Belimbing-managed listing.
     * Editable fields are the item's canonical content (title, price); quantity stays
     * inventory-driven and is shown read-only.
     */
    public function openListingModal(int $listingId): void
    {
        $listing = $this->managedListing($listingId);

        if ($listing === null || $listing->item === null) {
            session()->flash('error', __('Link this listing to a Belimbing item before editing it here.'));

            return;
        }

        $this->modalListingId = $listing->id;
        $this->modalTitle = (string) ($listing->item->title ?? '');
        $this->modalPrice = $listing->item->target_price_amount !== null
            ? number_format($listing->item->target_price_amount / 100, 2, '.', '')
            : '';
        $this->showListingModal = true;
    }

    public function saveAndPushListing(MarketplaceListingPushService $pushes): void
    {
        app(AuthorizationService::class)->authorize(Actor::forUser(Auth::user()), 'commerce.marketplace.execute');

        $listing = $this->modalListingId !== null ? $this->managedListing($this->modalListingId) : null;

        if ($listing === null || $listing->item === null) {
            session()->flash('error', __('This listing can no longer be edited here.'));
            $this->closeListingModal();

            return;
        }

        $validated = $this->validate([
            'modalTitle' => ['required', 'string', 'max:255'],
            'modalPrice' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ]);

        // Edit the item (the content source of truth), then push this one listing.
        $item = $listing->item;
        $item->title = $validated['modalTitle'];
        $item->target_price_amount = Money::fromDecimalString($validated['modalPrice'], $item->currency_code)?->minorAmount;
        $item->save();

        $result = $pushes->push($item, [$listing->channel]);
        $this->closeListingModal();

        if ($result['failures'] !== []) {
            $message = collect($result['failures'])->map(fn (array $f): string => $f['label'].': '.$f['message'])->first();
            session()->flash('error', __('Saved, but the push failed: :message', ['message' => $message]));

            return;
        }

        session()->flash('success', __('Saved and pushed to :channel.', ['channel' => $listing->channel]));
    }

    public function closeListingModal(): void
    {
        $this->showListingModal = false;
        $this->modalListingId = null;
        $this->modalTitle = '';
        $this->modalPrice = '';
        $this->resetValidation();
    }

    private function managedListing(int $listingId): ?Listing
    {
        return Listing::query()
            ->where('company_id', $this->companyId())
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('id', $listingId)
            ->whereNotNull('item_id')
            ->with('item')
            ->first();
    }

    /**
     * Pull from eBay: make Belimbing mirror the eBay store and bring in recent
     * orders, in one action (eBay is the remote, Belimbing the local working copy).
     *
     * It is fetch-style, not a blind merge: a Belimbing-managed listing that changed
     * on eBay is flagged as drifted rather than overwritten. Reconciliation against
     * the live active set (Trading API) adds legacy listings the Inventory pull
     * cannot see and retires ones no longer active — so the table mirrors eBay.
     */
    public function pullFromEbay(EbayMarketplaceChannel $channel): void
    {
        $this->authorizeSyncRun();

        $companyId = $this->companyId();

        try {
            $listings = $channel->pullListings($companyId);
            $orders = $channel->pullOrders($companyId);
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        // Mirror the full active set; best-effort so a Trading API hiccup never
        // breaks the Inventory pull + orders above.
        $reconcileNote = '';

        try {
            $reconcile = $channel->reconcileSellerListings($companyId);
            $reconcileNote = ' '.__('Mirrored :active live listing(s) (:created new, :ended ended).', [
                'active' => $reconcile->active,
                'created' => $reconcile->created,
                'ended' => $reconcile->ended,
            ]);

            if (! $reconcile->complete) {
                $reconcileNote .= ' '.__('Partial set — older listings were not retired.');
            }
        } catch (Throwable $exception) {
            $reconcileNote = ' '.__('Listing mirror skipped: :message', ['message' => $exception->getMessage()]);
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
        ).$reconcileNote);
    }

    public function openImportModal(): void
    {
        $this->sellerListings = [];
        $this->selectedImportIds = [];
        $this->listingsLoaded = false;
        $this->resetValidation();
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
        $this->sellerListings = [];
        $this->selectedImportIds = [];
        $this->listingsLoaded = false;
        $this->resetValidation();
    }

    /**
     * Load the seller's active eBay listings (Trading API GetMyeBaySelling) so the
     * user can pick which to import — including listings the Inventory-API pull
     * cannot see.
     */
    public function loadSellerListings(EbayMarketplaceChannel $channel): void
    {
        $this->authorizeSyncRun();

        try {
            $this->sellerListings = $channel->fetchSellerListings($this->companyId());
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->selectedImportIds = [];
        $this->listingsLoaded = true;

        if ($this->sellerListings === []) {
            session()->flash('warning', __('No active eBay listings were found for this account.'));
        }
    }

    public function toggleSelectAllImports(): void
    {
        $allIds = array_map(fn (array $listing): string => $listing['item_id'], $this->sellerListings);

        $this->selectedImportIds = count($this->selectedImportIds) === count($allIds) ? [] : $allIds;
    }

    /**
     * Import the ticked listings into Belimbing as listing records (visibility).
     * They land as legacy listings; adopting them for revise/end is a later step.
     */
    public function importSelectedListings(EbayMarketplaceChannel $channel): void
    {
        $this->authorizeSyncRun();

        $listingIds = collect($this->selectedImportIds)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($listingIds === []) {
            $this->addError('selectedImportIds', __('Select at least one listing to import.'));

            return;
        }

        try {
            $result = $channel->importSellerListings($this->companyId(), $listingIds);
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->closeImportModal();

        session()->flash('success', __(
            'Imported :fetched eBay listing(s) — :created new, :updated updated, :linked linked to inventory items.',
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
            ->when(! $this->includeEnded, fn (Builder $query) => $query->whereNull('ended_at'))
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
