<?php

use App\Modules\Commerce\Catalog\Livewire\Index;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\Description;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Models\Item;
use Livewire\Livewire;

test('catalog primitives can describe an inventory item', function (): void {
    $item = Item::factory()->create();
    $category = Category::factory()->create([
        'company_id' => $item->company_id,
        'code' => 'auto-lighting',
        'name' => 'Auto Lighting',
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($category)
        ->create([
            'code' => 'headlight-assembly',
            'name' => 'Headlight Assembly',
        ]);
    $attribute = Attribute::factory()
        ->forProductTemplate($template)
        ->create([
            'code' => 'oem_number',
            'name' => 'OEM Number',
        ]);

    $value = AttributeValue::factory()->create([
        'item_id' => $item->id,
        'attribute_id' => $attribute->id,
        'value' => ['text' => '33151-SNA-A01'],
        'display_value' => '33151-SNA-A01',
    ]);

    $description = Description::factory()->create([
        'item_id' => $item->id,
        'version' => 1,
        'title' => '2008 Honda Civic Driver Side Headlight',
        'body' => 'Used OEM driver side headlight with light scuffing.',
        'is_accepted' => true,
    ]);

    expect($item->catalogAttributeValues()->first()->is($value))->toBeTrue()
        ->and($item->descriptions()->first()->is($description))->toBeTrue()
        ->and($template->attributes()->first()->is($attribute))->toBeTrue()
        ->and($category->productTemplates()->first()->is($template))->toBeTrue();
});

test('catalog workbench can create categories templates and attributes', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.catalog.index'))
        ->assertOk()
        ->assertSee('Catalog Workbench');

    Livewire::test(Index::class)
        ->set('categoryName', 'Auto Lighting')
        ->set('categoryCode', 'auto-lighting')
        ->call('createCategory')
        ->assertHasNoErrors();

    $category = Category::query()->where('company_id', $user->company_id)->where('code', 'auto-lighting')->first();

    expect($category)->not()->toBeNull();

    Livewire::test(Index::class)
        ->set('templateCategoryId', $category->id)
        ->set('templateName', 'Headlight Assembly')
        ->set('templateCode', 'headlight-assembly')
        ->call('createTemplate')
        ->assertHasNoErrors();

    $template = ProductTemplate::query()->where('company_id', $user->company_id)->where('code', 'headlight-assembly')->first();

    expect($template)
        ->not()->toBeNull()
        ->and($template->category_id)->toBe($category->id);

    Livewire::test(Index::class)
        ->set('attributeCategoryId', $category->id)
        ->set('attributeProductTemplateId', $template->id)
        ->set('attributeName', 'OEM Number')
        ->set('attributeCode', 'oem_number')
        ->set('attributeType', Attribute::TYPE_TEXT)
        ->call('createAttribute')
        ->assertHasNoErrors();

    expect(Attribute::query()
        ->where('company_id', $user->company_id)
        ->where('product_template_id', $template->id)
        ->where('code', 'oem_number')
        ->exists())->toBeTrue();
});

test('catalog workbench supports searching and inline editing catalog rows', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $category = Category::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'auto-lighting',
        'name' => 'Auto Lighting',
    ]);
    $replacementCategory = Category::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'body-panels',
        'name' => 'Body Panels',
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($category)
        ->create([
            'code' => 'headlight-assembly',
            'name' => 'Headlight Assembly',
            'is_active' => true,
        ]);
    $attribute = Attribute::factory()
        ->forProductTemplate($template)
        ->create([
            'category_id' => $category->id,
            'code' => 'oem_number',
            'name' => 'OEM Number',
            'type' => Attribute::TYPE_TEXT,
            'options' => null,
            'is_required' => false,
        ]);
    Attribute::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'paint_code',
        'name' => 'Paint Code',
    ]);

    Livewire::test(Index::class)
        ->assertSee('OEM Number')
        ->set('search', 'paint')
        ->assertSee('Paint Code')
        ->assertDontSee('OEM Number')
        ->call('sort', 'name')
        ->assertSet('sortBy', 'name')
        ->assertSet('sortDir', 'asc')
        ->call('sort', 'name')
        ->assertSet('sortDir', 'desc')
        ->call('sort', 'template_name')
        ->assertSet('sortBy', 'template_name')
        ->call('setTab', 'categories')
        ->assertSet('sortBy', 'sort_order')
        ->call('sort', 'product_templates_count')
        ->assertSet('sortBy', 'product_templates_count')
        ->assertSet('sortDir', 'desc')
        ->call('saveCategoryField', $category->id, 'name', 'Lighting Parts')
        ->call('saveCategoryField', $category->id, 'sort_order', '12')
        ->call('setTab', 'templates')
        ->assertSet('sortBy', 'name')
        ->call('sort', 'category_name')
        ->assertSet('sortBy', 'category_name')
        ->call('saveTemplateField', $template->id, 'category_id', (string) $replacementCategory->id)
        ->call('toggleTemplateActive', $template->id)
        ->call('setTab', 'attributes')
        ->call('sort', 'is_required')
        ->assertSet('sortBy', 'is_required')
        ->call('saveAttributeField', $attribute->id, 'type', Attribute::TYPE_SELECT)
        ->call('saveAttributeField', $attribute->id, 'options', "Used\nNew")
        ->call('toggleAttributeRequired', $attribute->id)
        ->assertHasNoErrors();

    expect($category->refresh())
        ->name->toBe('Lighting Parts')
        ->sort_order->toBe(12)
        ->and($template->refresh())
        ->category_id->toBe($replacementCategory->id)
        ->is_active->toBeFalse()
        ->and($attribute->refresh())
        ->type->toBe(Attribute::TYPE_SELECT)
        ->options->toBe(['Used', 'New'])
        ->is_required->toBeTrue();
});
