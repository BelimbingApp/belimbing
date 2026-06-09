<?php

use App\Base\Media\Models\MediaAsset;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Livewire\Items\Index;
use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Inventory\Services\DefaultCurrencyResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const INVENTORY_OEM_NUMBER_ATTRIBUTE_NAME = 'OEM Number';
const INVENTORY_HEADLIGHT_TEMPLATE_NAME = 'Headlight Assembly';
const INVENTORY_BMW_135I_FITMENT_LABEL = '2011 BMW 135i';
const INVENTORY_FITMENT_TRIM = 'M Sport';
const INVENTORY_FITMENT_ENGINE = 'N55 3.0L';
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
        'quantity_on_hand' => 3,
        'storage_location' => 'Shelf A-03',
    ]);

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.index'))
        ->assertOk()
        ->assertSee('Inventory')
        ->assertSee('Qty')
        ->assertSee('Location')
        ->assertSee('ITEM-TEST123')
        ->assertSee('Driver side headlight assembly')
        ->assertSee('3')
        ->assertSee('Shelf A-03');
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
        ->set('quantityOnHand', 2)
        ->set('storageLocation', 'Rack B-12')
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
        ->and($item->quantity_on_hand)->toBe(2)
        ->and($item->storage_location)->toBe('Rack B-12')
        ->and($item->unit_cost_amount)->toBe(4000)
        ->and($item->target_price_amount)->toBe(12000)
        ->and($item->currency_code)->toBe('USD')
        ->and($item->sku)->toBe('HAM-HEADLIGHT-0001');
});

test('item can be created from a catalog template', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $category = Category::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Lighting',
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($category)
        ->create(['name' => INVENTORY_HEADLIGHT_TEMPLATE_NAME]);

    $this->get(route('commerce.inventory.items.create', ['template_id' => $template->id]))
        ->assertOk()
        ->assertSee('Template')
        ->assertSee(INVENTORY_HEADLIGHT_TEMPLATE_NAME);

    Livewire::test(Create::class)
        ->set('sku', 'HAM-TEMPLATE-0001')
        ->set('title', 'Template-created headlight')
        ->set('status', Item::STATUS_DRAFT)
        ->set('currencyCode', 'MYR')
        ->set('productTemplateId', $template->id)
        ->call('store')
        ->assertHasNoErrors();

    $item = Item::query()
        ->where('sku', 'HAM-TEMPLATE-0001')
        ->first();

    expect($item)
        ->not()->toBeNull()
        ->category_id->toBe($category->id)
        ->product_template_id->toBe($template->id);
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

test('item detail page renders core readiness checklist from commerce plugins', function (): void {
    $user = createAdminUser();

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'ITEM-CHECKLIST',
        'title' => 'Checklist test item',
        'quantity_on_hand' => 0,
        'target_price_amount' => null,
    ]);

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.show', $item))
        ->assertOk()
        ->assertSee('Item checklist')
        ->assertSee('Choose a catalog fit')
        ->assertSee('Set a target price')
        ->assertSee('Quantity is zero')
        ->assertSee('Add item photos')
        ->assertSee('Add listing copy');
});

test('item facts can be updated directly from the detail page component', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'ITEM-EDIT123',
        'title' => 'Unedited inventory item',
        'status' => Item::STATUS_DRAFT,
        'quantity_on_hand' => 1,
        'storage_location' => 'Old Shelf',
        'unit_cost_amount' => 4000,
        'target_price_amount' => 12000,
        'currency_code' => 'MYR',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->call('saveField', 'sku', 'ITEM-EDIT456')
        ->call('saveField', 'title', 'Edited inventory item')
        ->call('saveField', 'notes', 'Updated condition notes.')
        ->call('saveField', 'status', Item::STATUS_READY)
        ->call('saveField', 'quantity_on_hand', '5')
        ->call('saveField', 'storage_location', 'Shelf C-09')
        ->call('saveMoneyField', 'unit_cost_amount', '45.50')
        ->call('saveMoneyField', 'target_price_amount', '130.00')
        ->call('saveField', 'currency_code', 'myr');

    $item->refresh();

    expect($item->sku)->toBe('ITEM-EDIT456')
        ->and($item->title)->toBe('Edited inventory item')
        ->and($item->notes)->toBe('Updated condition notes.')
        ->and($item->status)->toBe(Item::STATUS_READY)
        ->and($item->quantity_on_hand)->toBe(5)
        ->and($item->storage_location)->toBe('Shelf C-09')
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

    $asset = $photo->mediaAsset;
    expect($asset)->not()->toBeNull()
        ->and($asset->disk)->toBe('local')
        ->and($asset->original_filename)->toBe('front.jpg')
        ->and($asset->mime_type)->toBe('image/jpeg');

    Storage::disk('local')->assertExists($asset->storage_key);

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->call('deletePhoto', $photo->id);

    expect(ItemPhoto::query()->whereKey($photo->id)->exists())->toBeFalse();
    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($asset->storage_key);
});

test('item fitments can be added and removed from the detail page component', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-FITMENT-1',
        'title' => 'BMW rear caliper pair',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->assertSee('Fitment')
        ->set('fitmentYear', '2011')
        ->set('fitmentMake', 'BMW')
        ->set('fitmentModel', '135i')
        ->set('fitmentTrim', 'Base Coupe 2-Door')
        ->set('fitmentEngine', '3.0L')
        ->set('fitmentNotes', 'Confirmed from donor vehicle.')
        ->call('addFitment')
        ->assertHasNoErrors()
        ->assertSee(INVENTORY_BMW_135I_FITMENT_LABEL);

    $fitment = ItemFitment::query()->where('item_id', $item->id)->firstOrFail();

    expect($fitment->company_id)->toBe($user->company_id)
        ->and($fitment->is_universal)->toBeFalse()
        ->and($fitment->compatibility_properties)->toBe([
            'Year' => '2011',
            'Make' => 'BMW',
            'Model' => '135i',
            'Trim' => 'Base Coupe 2-Door',
            'Engine' => '3.0L',
        ])
        ->and($fitment->confidence)->toBe(ItemFitment::CONFIDENCE_SELLER_CONFIRMED);

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->call('deleteFitment', $fitment->id)
        ->assertDontSee(INVENTORY_BMW_135I_FITMENT_LABEL);

    expect(ItemFitment::query()->whereKey($fitment->id)->exists())->toBeFalse();
});

test('item fitment requires either compatibility values or explicit universal fit', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create(['company_id' => $user->company_id]);

    Livewire::test(Show::class, ['item' => $item])
        ->call('addFitment')
        ->assertHasErrors(['fitmentYear']);

    Livewire::test(Show::class, ['item' => $item])
        ->set('fitmentUniversal', true)
        ->call('addFitment')
        ->assertHasNoErrors()
        ->assertSee('Universal fit');

    $fitment = ItemFitment::query()->where('item_id', $item->id)->firstOrFail();

    expect($fitment->is_universal)->toBeTrue()
        ->and($fitment->compatibility_properties)->toBeNull();
});

test('item fitments can be imported in bulk rows', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create(['company_id' => $user->company_id]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('fitmentBulk', "2011,BMW,135i,Base Coupe,3.0L\n2012,BMW,135i,Base Coupe,3.0L")
        ->call('importFitments')
        ->assertHasNoErrors()
        ->assertSee(INVENTORY_BMW_135I_FITMENT_LABEL)
        ->assertSee('2012 BMW 135i');

    expect(ItemFitment::query()->where('item_id', $item->id)->count())->toBe(2);
});

test('item fitments can be edited from the detail page component', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create(['company_id' => $user->company_id]);
    $fitment = ItemFitment::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'is_universal' => false,
        'compatibility_properties' => ['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'],
        'display_year' => '2011',
        'display_make' => 'BMW',
        'display_model' => '135i',
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->call('editFitment', $fitment->id)
        ->assertSet('fitmentYear', '2011')
        ->set('fitmentYear', '2012')
        ->set('fitmentTrim', INVENTORY_FITMENT_TRIM)
        ->set('fitmentEngine', INVENTORY_FITMENT_ENGINE)
        ->set('fitmentNotes', 'Corrected after donor VIN review.')
        ->call('updateFitment')
        ->assertHasNoErrors()
        ->assertSet('editingFitmentId', null)
        ->assertSee('2012 BMW 135i');

    expect($fitment->fresh())
        ->display_year->toBe('2012')
        ->display_trim->toBe(INVENTORY_FITMENT_TRIM)
        ->display_engine->toBe(INVENTORY_FITMENT_ENGINE)
        ->notes->toBe('Corrected after donor VIN review.')
        ->compatibility_properties->toBe([
            'Year' => '2012',
            'Make' => 'BMW',
            'Model' => '135i',
            'Trim' => INVENTORY_FITMENT_TRIM,
            'Engine' => INVENTORY_FITMENT_ENGINE,
        ]);
});

test('item fitment can be bootstrapped from configured catalog attributes', function (): void {
    config()->set('commerce.inventory.fitment_attribute_codes', [
        'Year' => 'fitment_year',
        'Make' => 'fitment_make',
        'Model' => 'fitment_model',
        'Trim' => 'fitment_trim',
        'Engine' => 'fitment_engine',
    ]);

    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create(['company_id' => $user->company_id]);

    foreach ([
        'fitment_year' => '2011',
        'fitment_make' => 'BMW',
        'fitment_model' => '135i',
        'fitment_trim' => 'Base Coupe',
        'fitment_engine' => '3.0L',
    ] as $code => $value) {
        $attribute = CatalogAttribute::factory()->create([
            'company_id' => $user->company_id,
            'code' => $code,
        ]);

        AttributeValue::factory()->create([
            'item_id' => $item->id,
            'attribute_id' => $attribute->id,
            'value' => ['text' => $value],
            'display_value' => $value,
        ]);
    }

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->assertSee('Create from attributes')
        ->call('bootstrapFitmentFromAttributes')
        ->assertHasNoErrors()
        ->assertSee('2011 BMW 135i');

    $fitment = ItemFitment::query()->where('item_id', $item->id)->firstOrFail();

    expect($fitment->compatibility_properties)->toBe([
        'Year' => '2011',
        'Make' => 'BMW',
        'Model' => '135i',
        'Trim' => 'Base Coupe',
        'Engine' => '3.0L',
    ])->and($fitment->notes)->toBe('Created from item attributes.');
});

test('item fitments can be copied from another item in the same company', function (): void {
    $user = createAdminUser();
    $otherUser = createAdminUser();
    $this->actingAs($user);

    $source = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'DONOR-135I',
        'title' => 'BMW donor vehicle',
    ]);
    $target = Item::factory()->create(['company_id' => $user->company_id]);
    $outsideCompanySource = Item::factory()->create(['company_id' => $otherUser->company_id]);

    ItemFitment::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $source->id,
        'is_universal' => false,
        'compatibility_properties' => ['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'],
        'display_year' => '2011',
        'display_make' => 'BMW',
        'display_model' => '135i',
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);
    ItemFitment::query()->create([
        'company_id' => $otherUser->company_id,
        'item_id' => $outsideCompanySource->id,
        'is_universal' => true,
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);

    Livewire::test(Show::class, ['item' => $target])
        ->assertSee('DONOR-135I')
        ->set('copyFitmentsFromItemId', $outsideCompanySource->id)
        ->call('copyFitmentsFromItem')
        ->assertHasErrors(['copyFitmentsFromItemId'])
        ->set('copyFitmentsFromItemId', $source->id)
        ->call('copyFitmentsFromItem')
        ->assertHasNoErrors()
        ->assertSee('2011 BMW 135i');

    $copied = ItemFitment::query()->where('item_id', $target->id)->firstOrFail();

    expect($copied->compatibility_properties)->toBe(['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'])
        ->and($copied->notes)->toBe('Copied from DONOR-135I.');
});

test('inventory list shows fitment coverage and supports fitment count sorting', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $withoutFitment = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'NO-FITMENT',
    ]);
    $withFitment = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'HAS-FITMENT',
    ]);

    ItemFitment::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $withFitment->id,
        'is_universal' => true,
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Fitment')
        ->assertSee('Universal')
        ->assertSee('None')
        ->call('sort', 'fitments_count')
        ->assertSeeInOrder([$withFitment->sku, $withoutFitment->sku])
        ->call('sort', 'fitments_count')
        ->assertSeeInOrder([$withoutFitment->sku, $withFitment->sku]);
});

test('item detail page can edit catalog attributes and the listing description', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
    ]);
    $attribute = CatalogAttribute::factory()->create([
        'company_id' => $user->company_id,
        'name' => INVENTORY_OEM_NUMBER_ATTRIBUTE_NAME,
        'code' => 'oem_number',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('selectedAttributeId', $attribute->id)
        ->set('attributeValue', '33151-SNA-A01')
        ->call('saveAttributeValue')
        ->call('saveField', 'description', 'Used OEM driver side headlight with light scuffing.')
        ->assertHasNoErrors();

    $value = AttributeValue::query()
        ->where('item_id', $item->id)
        ->where('attribute_id', $attribute->id)
        ->first();

    expect($value)
        ->not()->toBeNull()
        ->and($value->display_value)->toBe('33151-SNA-A01')
        ->and($item->fresh()->description)->toBe('Used OEM driver side headlight with light scuffing.');
});

test('item detail page saves the currency from the combobox selection', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'currency_code' => 'MYR',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('currencyCode', 'usd')
        ->assertHasNoErrors();

    expect($item->fresh()->currency_code)->toBe('USD');
});

test('item detail page allows clearing the currency combobox before selecting another currency', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'currency_code' => 'MYR',
    ]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('currencyCode', null)
        ->assertHasNoErrors()
        ->assertSet('currencyCode', null)
        ->set('currencyCode', 'usd')
        ->assertHasNoErrors()
        ->assertSet('currencyCode', 'USD');

    expect($item->fresh()->currency_code)->toBe('USD');
});

test('item detail page assigns catalog fit and filters applicable attributes', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
    ]);
    $category = Category::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Auto Lighting',
    ]);
    $otherCategory = Category::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Body Panels',
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($category)
        ->create([
            'name' => INVENTORY_HEADLIGHT_TEMPLATE_NAME,
        ]);
    $otherTemplate = ProductTemplate::factory()
        ->forCategory($otherCategory)
        ->create([
            'name' => 'Door Shell',
        ]);

    $globalAttribute = CatalogAttribute::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Condition Grade',
    ]);
    $categoryAttribute = CatalogAttribute::factory()
        ->forCategory($category)
        ->create([
            'name' => INVENTORY_OEM_NUMBER_ATTRIBUTE_NAME,
        ]);
    $templateAttribute = CatalogAttribute::factory()
        ->forProductTemplate($template)
        ->create([
            'name' => 'Interchange Number',
        ]);
    $otherAttribute = CatalogAttribute::factory()
        ->forProductTemplate($otherTemplate)
        ->create([
            'name' => 'Paint Code',
        ]);

    Livewire::test(Show::class, ['item' => $item])
        ->set('catalogProductTemplateId', $template->id)
        ->assertSet('catalogCategoryId', $category->id)
        ->call('saveCatalogAssignment')
        ->assertHasNoErrors()
        ->assertSee('Condition Grade')
        ->assertSee(INVENTORY_OEM_NUMBER_ATTRIBUTE_NAME)
        ->assertSee('Interchange Number')
        ->assertDontSee('Paint Code')
        ->set('selectedAttributeId', $otherAttribute->id)
        ->set('attributeValue', 'NH-731P')
        ->call('saveAttributeValue')
        ->assertHasErrors(['selectedAttributeId'])
        ->set('selectedAttributeId', $templateAttribute->id)
        ->set('attributeValue', 'HO2502124')
        ->call('saveAttributeValue')
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->category_id)->toBe($category->id)
        ->and($item->product_template_id)->toBe($template->id)
        ->and(AttributeValue::query()
            ->where('item_id', $item->id)
            ->where('attribute_id', $templateAttribute->id)
            ->where('display_value', 'HO2502124')
            ->exists())->toBeTrue()
        ->and(AttributeValue::query()
            ->where('item_id', $item->id)
            ->where('attribute_id', $globalAttribute->id)
            ->exists())->toBeFalse()
        ->and(AttributeValue::query()
            ->where('item_id', $item->id)
            ->where('attribute_id', $categoryAttribute->id)
            ->exists())->toBeFalse();
});
