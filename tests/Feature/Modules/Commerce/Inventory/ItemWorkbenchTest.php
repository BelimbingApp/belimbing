<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Description as CatalogDescription;
use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Inventory\Services\DefaultCurrencyResolver;
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

    app(SettingsService::class)->set(
        DefaultCurrencyResolver::SETTINGS_KEY,
        'USD',
        Scope::company($user->company_id),
    );

    $component = Livewire::test(Create::class)
        ->set('sku', 'HAM-HEADLIGHT-0001')
        ->set('title', '2008 Honda Civic driver side headlight')
        ->set('notes', 'Light scuff on lower-left lens.')
        ->set('status', Item::STATUS_DRAFT)
        ->set('unitCostAmount', '40.00')
        ->set('targetPriceAmount', '120.00')
        ->call('store');

    $item = Item::query()
        ->where('title', '2008 Honda Civic driver side headlight')
        ->first();

    $component->assertRedirect(route('commerce.inventory.items.show', $item));

    expect($item)
        ->not()->toBeNull()
        ->and($item->company_id)->toBe($user->company_id)
        ->and($item->status)->toBe(Item::STATUS_DRAFT)
        ->and($item->unit_cost_amount)->toBe(4000)
        ->and($item->target_price_amount)->toBe(12000)
        ->and($item->currency_code)->toBe('USD')
        ->and($item->sku)->toBe('HAM-HEADLIGHT-0001');
});

test('item sku must be unique within a company', function (): void {
    $user = createAdminUser();
    $otherUser = createAdminUser();
    $this->actingAs($user);

    Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'HAM-DUPLICATE',
    ]);
    Item::factory()->create([
        'company_id' => $otherUser->company_id,
        'sku' => 'HAM-DUPLICATE',
    ]);

    Livewire::test(Create::class)
        ->set('sku', 'HAM-DUPLICATE')
        ->set('title', 'Duplicate SKU in same company')
        ->set('status', Item::STATUS_DRAFT)
        ->set('currencyCode', 'MYR')
        ->call('store')
        ->assertHasErrors(['sku' => 'unique']);

    Livewire::test(Create::class)
        ->set('sku', 'HAM-UNIQUE')
        ->set('title', 'Unique SKU in same company')
        ->set('status', Item::STATUS_DRAFT)
        ->set('currencyCode', 'MYR')
        ->call('store')
        ->assertHasNoErrors();
});

test('authenticated users can view an inventory item detail page', function (): void {
    $user = createAdminUser();

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'ITEM-SHOW123',
        'title' => 'Mirrorless camera body',
        'notes' => 'Includes battery and charger.',
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
        ->call('saveField', 'sku', 'ITEM-EDIT456')
        ->call('saveField', 'title', 'Edited inventory item')
        ->call('saveField', 'notes', 'Updated condition notes.')
        ->call('saveField', 'status', Item::STATUS_READY)
        ->call('saveMoneyField', 'unit_cost_amount', '45.50')
        ->call('saveMoneyField', 'target_price_amount', '130.00')
        ->call('saveField', 'currency_code', 'myr');

    $item->refresh();

    expect($item->sku)->toBe('ITEM-EDIT456')
        ->and($item->title)->toBe('Edited inventory item')
        ->and($item->notes)->toBe('Updated condition notes.')
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

test('item detail page can edit catalog attributes and description versions', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
    ]);
    $attribute = CatalogAttribute::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'OEM Number',
        'code' => 'oem_number',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('selectedAttributeId', $attribute->id)
        ->set('attributeValue', '33151-SNA-A01')
        ->call('saveAttributeValue')
        ->set('descriptionTitle', '2008 Honda Civic Driver Side Headlight')
        ->set('descriptionBody', 'Used OEM driver side headlight with light scuffing.')
        ->call('addDescription')
        ->assertHasNoErrors();

    $value = AttributeValue::query()
        ->where('item_id', $item->id)
        ->where('attribute_id', $attribute->id)
        ->first();
    $description = CatalogDescription::query()
        ->where('item_id', $item->id)
        ->first();

    expect($value)
        ->not()->toBeNull()
        ->and($value->display_value)->toBe('33151-SNA-A01')
        ->and($description)->not()->toBeNull()
        ->and($description->version)->toBe(1);

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->call('acceptDescription', $description->id)
        ->assertHasNoErrors();

    expect($description->fresh()->is_accepted)->toBeTrue();
});
