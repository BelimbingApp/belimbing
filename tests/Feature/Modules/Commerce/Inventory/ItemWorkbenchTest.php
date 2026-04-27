<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests are redirected to login from inventory item pages', function (): void {
    $item = Item::factory()->create();

    $this->get(route('commerce.inventory.items.index'))->assertRedirect(route('login'));
    $this->get(route('commerce.inventory.items.create'))->assertRedirect(route('login'));
    $this->get(route('commerce.inventory.items.show', $item))->assertRedirect(route('login'));
});

test('authenticated users can view the inventory workbench', function (): void {
    $user = createAdminUser();

    Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'ITEM-TEST123',
        'title' => 'Driver side headlight assembly',
    ]);

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.index'))
        ->assertOk()
        ->assertSee('Inventory Workbench')
        ->assertSee('ITEM-TEST123')
        ->assertSee('Driver side headlight assembly');
});

test('item can be created from the browser workbench component', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Create::class)
        ->set('title', '2008 Honda Civic driver side headlight')
        ->set('description', 'Light scuff on lower-left lens.')
        ->set('status', Item::STATUS_DRAFT)
        ->set('unitCostAmount', '40.00')
        ->set('targetPriceAmount', '120.00')
        ->set('currencyCode', 'MYR')
        ->call('store')
        ->assertRedirect(route('commerce.inventory.items.index'));

    $item = Item::query()
        ->where('title', '2008 Honda Civic driver side headlight')
        ->first();

    expect($item)
        ->not()->toBeNull()
        ->and($item->company_id)->toBe($user->company_id)
        ->and($item->status)->toBe(Item::STATUS_DRAFT)
        ->and($item->unit_cost_amount)->toBe(4000)
        ->and($item->target_price_amount)->toBe(12000)
        ->and($item->currency_code)->toBe('MYR')
        ->and($item->sku)->toStartWith('ITEM-');
});

test('authenticated users can view an inventory item detail page', function (): void {
    $user = createAdminUser();

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'ITEM-SHOW123',
        'title' => 'Mirrorless camera body',
        'description' => 'Includes battery and charger.',
        'unit_cost_amount' => 95000,
        'target_price_amount' => 145000,
        'currency_code' => 'MYR',
    ]);

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.show', $item))
        ->assertOk()
        ->assertSee('ITEM-SHOW123')
        ->assertSee('Mirrorless camera body')
        ->assertSee('Includes battery and charger.')
        ->assertSee('950.00')
        ->assertSee('1450.00');
});

test('item facts can be updated directly from the detail page component', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'ITEM-EDIT123',
        'title' => 'Unedited inventory item',
        'status' => Item::STATUS_DRAFT,
        'unit_cost_amount' => 4000,
        'target_price_amount' => 12000,
        'currency_code' => 'MYR',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->call('saveField', 'title', 'Edited inventory item')
        ->call('saveField', 'description', 'Updated condition notes.')
        ->call('saveField', 'status', Item::STATUS_READY)
        ->call('saveMoneyField', 'unit_cost_amount', '45.50')
        ->call('saveMoneyField', 'target_price_amount', '130.00')
        ->call('saveField', 'currency_code', 'myr');

    $item->refresh();

    expect($item->title)->toBe('Edited inventory item')
        ->and($item->description)->toBe('Updated condition notes.')
        ->and($item->status)->toBe(Item::STATUS_READY)
        ->and($item->unit_cost_amount)->toBe(4550)
        ->and($item->target_price_amount)->toBe(13000)
        ->and($item->currency_code)->toBe('MYR');
});

test('users cannot view inventory items from another company', function (): void {
    $user = createAdminUser();
    $otherUser = createAdminUser();

    $item = Item::factory()->create([
        'company_id' => $otherUser->company_id,
    ]);

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.show', $item))
        ->assertNotFound();
});

test('item photos can be uploaded and deleted from the detail page component', function (): void {
    Storage::fake('local');

    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('photoFiles', [UploadedFile::fake()->create('front.jpg', 64, 'image/jpeg')])
        ->call('uploadPhotos')
        ->assertHasNoErrors();

    $photo = ItemPhoto::query()->where('item_id', $item->id)->first();
    expect($photo)->not()->toBeNull();

    Storage::disk('local')->assertExists($photo->storage_key);

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->call('deletePhoto', $photo->id);

    expect(ItemPhoto::query()->whereKey($photo->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($photo->storage_key);
});
