<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannel;
use App\Modules\Commerce\Marketplace\DTO\MarketplaceChannelDescriptor;
use App\Modules\Commerce\Marketplace\DTO\MarketplacePullResult;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Services\MarketplaceAvailabilitySyncService;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use Livewire\Livewire;

/**
 * A self-contained channel double so the availability-sync logic is tested
 * channel-generically — no eBay, no HTTP. Records the write operations it
 * receives.
 */
class FakeAvailabilityChannel implements MarketplaceChannel
{
    /** @var list<array{op: string, listing: int}> */
    public static array $calls = [];

    public function key(): string
    {
        return 'fake';
    }

    public function pullListings(int $companyId): MarketplacePullResult
    {
        return new MarketplacePullResult('fake', 0, 0, 0, 0);
    }

    public function pullOrders(int $companyId): MarketplacePullResult
    {
        return new MarketplacePullResult('fake', 0, 0, 0, 0);
    }

    public function createListing(Item $item): array
    {
        self::$calls[] = ['op' => 'create', 'listing' => 0];

        return ['ok' => true];
    }

    public function reviseListing(Listing $listing): array
    {
        self::$calls[] = ['op' => 'revise', 'listing' => $listing->id];

        return ['ok' => true];
    }

    public function endListing(Listing $listing): array
    {
        self::$calls[] = ['op' => 'end', 'listing' => $listing->id];
        $listing->update(['status' => 'UNPUBLISHED', 'ended_at' => now()]);

        return ['ok' => true];
    }

    public function refreshListingDraft(Item $item): ListingDraft
    {
        return new ListingDraft;
    }
}

beforeEach(function (): void {
    FakeAvailabilityChannel::$calls = [];

    app(MarketplaceChannelRegistry::class)->register(new MarketplaceChannelDescriptor(
        key: 'fake',
        label: 'Fake Channel',
        channelClass: FakeAvailabilityChannel::class,
        capabilities: ['create_listing' => true, 'revise_listing' => true, 'end_listing' => true],
    ));
});

function seedFakeChannelListing(int $companyId, array $itemOverrides = [], array $listingOverrides = []): Item
{
    $item = Item::factory()->create(array_merge([
        'company_id' => $companyId,
        'status' => Item::STATUS_LISTED,
    ], $itemOverrides));

    Listing::query()->create(array_merge([
        'company_id' => $companyId,
        'item_id' => $item->id,
        'channel' => 'fake',
        'external_listing_id' => 'FAKE-'.$item->id,
        'external_offer_id' => 'FAKE-OFFER-'.$item->id,
        'external_sku' => $item->sku,
        'marketplace_id' => 'FAKE',
        'title' => $item->title,
        'status' => 'ACTIVE',
        'management_state' => 'belimbing_managed',
        'drift_status' => 'in_sync',
        'price_amount' => 12999,
        'currency_code' => 'USD',
        'listed_at' => now()->subDay(),
        'last_synced_at' => now()->subDay(),
    ], $listingOverrides));

    return $item->fresh();
}

test('zero quantity ends the listing on every channel (overselling guard)', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $item = seedFakeChannelListing($user->company_id, ['quantity_on_hand' => 0]);

    $result = app(MarketplaceAvailabilitySyncService::class)->syncItem($item);

    expect($result['available'])->toBe(0)
        ->and(collect($result['ended'])->pluck('channel')->all())->toBe(['fake'])
        ->and($result['revised'])->toBe([])
        ->and(collect(FakeAvailabilityChannel::$calls)->pluck('op')->all())->toBe(['end']);
});

test('positive quantity revises the listing so the channel reflects the new stock', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $item = seedFakeChannelListing($user->company_id, ['quantity_on_hand' => 5]);

    $result = app(MarketplaceAvailabilitySyncService::class)->syncItem($item);

    expect(collect($result['revised'])->pluck('channel')->all())->toBe(['fake'])
        ->and($result['ended'])->toBe([])
        ->and($result['failures'])->toBe([])
        ->and(collect(FakeAvailabilityChannel::$calls)->pluck('op')->all())->toBe(['revise']);
});

test('an unmanaged (imported) listing is reported, never written', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $item = seedFakeChannelListing($user->company_id, ['quantity_on_hand' => 0], ['management_state' => 'imported']);

    $result = app(MarketplaceAvailabilitySyncService::class)->syncItem($item);

    expect($result['ended'])->toBe([])
        ->and($result['revised'])->toBe([])
        ->and(collect($result['skipped'])->pluck('channel')->all())->toBe(['fake'])
        ->and(FakeAvailabilityChannel::$calls)->toBe([]);
});

test('a drifted listing is skipped so the external change is not clobbered', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $item = seedFakeChannelListing($user->company_id, ['quantity_on_hand' => 0], ['drift_status' => 'drifted']);

    $result = app(MarketplaceAvailabilitySyncService::class)->syncItem($item);

    expect(collect($result['skipped'])->pluck('channel')->all())->toBe(['fake'])
        ->and(FakeAvailabilityChannel::$calls)->toBe([]);
});

test('a sale on one channel ends the item on the others (no overselling for one-offs)', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    // One physical unit listed on two channels.
    $item = seedFakeChannelListing($user->company_id, ['quantity_on_hand' => 1]);
    Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => 'fake',
        'external_listing_id' => 'FAKE-SECOND-'.$item->id,
        'external_offer_id' => 'FAKE-OFFER-SECOND-'.$item->id,
        'external_sku' => $item->sku,
        'marketplace_id' => 'FAKE2',
        'title' => $item->title,
        'status' => 'ACTIVE',
        'management_state' => 'belimbing_managed',
        'drift_status' => 'in_sync',
        'currency_code' => 'USD',
    ]);

    // It sold: quantity drops to 0.
    $item->update(['quantity_on_hand' => 0]);
    $result = app(MarketplaceAvailabilitySyncService::class)->syncItem($item->fresh());

    expect(count($result['ended']))->toBe(2)
        ->and(collect(FakeAvailabilityChannel::$calls)->pluck('op')->all())->toBe(['end', 'end']);
});

test('editing item quantity to zero on the item page ends its channel listings', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $item = seedFakeChannelListing($user->company_id, ['quantity_on_hand' => 1]);

    Livewire::actingAs($user)
        ->test(Show::class, ['item' => $item])
        ->call('saveField', 'quantity_on_hand', 0)
        ->assertHasNoErrors()
        ->assertSee('Out of stock');

    expect(collect(FakeAvailabilityChannel::$calls)->pluck('op')->all())->toBe(['end']);
});
