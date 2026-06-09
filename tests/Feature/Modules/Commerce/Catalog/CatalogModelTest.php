<?php

use App\Modules\Commerce\Catalog\Livewire\Index;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Models\Item;
use Livewire\Livewire;

const CATALOG_TEST_CATEGORY_NAME = 'Auto Lighting';
const CATALOG_TEST_TEMPLATE_NAME = 'Headlight Assembly';
const CATALOG_TEST_ATTRIBUTE_NAME = 'OEM Number';

test('catalog primitives can describe an inventory item', function (): void {
    $item = Item::factory()->create();
    $category = Category::factory()->create([
        'company_id' => $item->company_id,
        'code' => 'auto-lighting',
        'name' => CATALOG_TEST_CATEGORY_NAME,
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($category)
        ->create([
            'code' => 'headlight-assembly',
            'name' => CATALOG_TEST_TEMPLATE_NAME,
        ]);
    $attribute = Attribute::factory()
        ->forProductTemplate($template)
        ->create([
            'code' => 'oem_number',
            'name' => CATALOG_TEST_ATTRIBUTE_NAME,
        ]);

    $value = AttributeValue::factory()->create([
        'item_id' => $item->id,
        'attribute_id' => $attribute->id,
        'value' => ['text' => '33151-SNA-A01'],
        'display_value' => '33151-SNA-A01',
    ]);

    expect($item->catalogAttributeValues()->first()->is($value))->toBeTrue()
        ->and($template->attributes()->first()->is($attribute))->toBeTrue()
        ->and($category->productTemplates()->first()->is($template))->toBeTrue();
});

test('catalog workbench can create categories templates and attributes', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('commerce.catalog.index'))
        ->assertOk()
        ->assertSee('Catalog');

    $this->get(route('commerce.catalog.categories'))
        ->assertOk()
        ->assertSee('Categories');

    $this->get(route('commerce.catalog.templates'))
        ->assertOk()
        ->assertSee('Templates');

    $this->get(route('commerce.catalog.attributes'))
        ->assertOk()
        ->assertSee('Attributes');

    Livewire::test(Index::class)
        ->set('categoryName', CATALOG_TEST_CATEGORY_NAME)
        ->set('categoryCode', 'auto-lighting')
        ->call('createCategory')
        ->assertHasNoErrors();

    $category = Category::query()->where('company_id', $user->company_id)->where('code', 'auto-lighting')->first();

    expect($category)->not()->toBeNull();

    Livewire::test(Index::class)
        ->set('categoryParentId', $category->id)
        ->set('categoryName', 'Headlights')
        ->set('categoryCode', 'headlights')
        ->call('createCategory')
        ->assertHasNoErrors();

    $childCategory = Category::query()->where('company_id', $user->company_id)->where('code', 'headlights')->first();

    expect($childCategory)
        ->not()->toBeNull()
        ->parent_id->toBe($category->id)
        ->path_label->toBe(CATALOG_TEST_CATEGORY_NAME.' › Headlights');

    Livewire::test(Index::class)
        ->set('templateCategoryId', $childCategory->id)
        ->set('templateName', CATALOG_TEST_TEMPLATE_NAME)
        ->set('templateCode', 'headlight-assembly')
        ->call('createTemplate')
        ->assertHasNoErrors();

    $template = ProductTemplate::query()->where('company_id', $user->company_id)->where('code', 'headlight-assembly')->first();

    expect($template)
        ->not()->toBeNull()
        ->and($template->category_id)->toBe($childCategory->id);

    Livewire::test(Index::class)
        ->set('attributeCategoryId', $childCategory->id)
        ->set('attributeProductTemplateId', $template->id)
        ->set('attributeName', CATALOG_TEST_ATTRIBUTE_NAME)
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

test('catalog category hierarchy prevents cycles', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $parent = Category::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Lighting',
    ]);
    $child = Category::factory()->create([
        'company_id' => $user->company_id,
        'parent_id' => $parent->id,
        'name' => 'Headlights',
    ]);

    Livewire::test(Index::class)
        ->call('saveCategoryField', $parent->id, 'parent_id', (string) $parent->id)
        ->assertHasErrors('categoryParentId');

    Livewire::test(Index::class)
        ->call('saveCategoryField', $parent->id, 'parent_id', (string) $child->id)
        ->assertHasErrors('categoryParentId');

    expect($parent->refresh()->parent_id)->toBeNull();
});

test('catalog category workspace selects tree nodes and scopes category setup actions', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $parent = Category::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'auto-parts',
        'name' => 'Auto Parts',
    ]);
    $child = Category::factory()->create([
        'company_id' => $user->company_id,
        'parent_id' => $parent->id,
        'code' => 'auto-lighting',
        'name' => CATALOG_TEST_CATEGORY_NAME,
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($child)
        ->create(['name' => CATALOG_TEST_TEMPLATE_NAME]);
    $attribute = Attribute::factory()->create([
        'company_id' => $user->company_id,
        'category_id' => $child->id,
        'product_template_id' => null,
        'code' => 'side',
        'name' => 'Side',
        'is_required' => false,
    ]);

    Livewire::test(Index::class, ['tab' => 'categories'])
        ->assertSee('Browse the selling taxonomy')
        ->assertSee('Auto Parts')
        ->assertSet('expandedCategoryIds', [$parent->id])
        ->call('selectCategory', $child->id)
        ->assertSet('selectedCategoryId', $child->id)
        ->assertSet('expandedCategoryIds', [$parent->id])
        ->assertSee(CATALOG_TEST_CATEGORY_NAME)
        ->assertSee('Direct attributes')
        ->assertSee('Side')
        ->assertSee(CATALOG_TEST_TEMPLATE_NAME)
        ->call('toggleAllCategoryExpansion')
        ->assertSet('expandedCategoryIds', [])
        ->call('toggleAllCategoryExpansion')
        ->assertSet('expandedCategoryIds', [$parent->id])
        ->call('selectCategory', $child->id)
        ->call('addChildCategory', $child->id)
        ->assertSet('tab', 'categories')
        ->assertSet('createKind', 'categories')
        ->assertSet('categoryParentId', $child->id)
        ->call('addCategoryAttribute', $child->id)
        ->assertSet('tab', 'categories')
        ->assertSet('createKind', 'attributes')
        ->assertSet('attributeCategoryId', $child->id)
        ->call('addCategoryTemplate', $child->id)
        ->assertSet('tab', 'categories')
        ->assertSet('createKind', 'templates')
        ->assertSet('templateCategoryId', $child->id)
        ->call('showCategoryAttributes', $child->id)
        ->assertSet('tab', 'attributes')
        ->assertSet('filterCategoryId', (string) $child->id)
        ->call('toggleAttributeRequired', $attribute->id)
        ->assertHasNoErrors();

    expect($attribute->refresh()->is_required)->toBeTrue()
        ->and($template->category_id)->toBe($child->id);
});

test('catalog workbench supports searching and inline editing catalog rows', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $category = Category::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'auto-lighting',
        'name' => CATALOG_TEST_CATEGORY_NAME,
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
            'name' => CATALOG_TEST_TEMPLATE_NAME,
            'is_active' => true,
        ]);
    $attribute = Attribute::factory()
        ->forProductTemplate($template)
        ->create([
            'category_id' => $category->id,
            'code' => 'oem_number',
            'name' => CATALOG_TEST_ATTRIBUTE_NAME,
            'type' => Attribute::TYPE_TEXT,
            'options' => null,
            'is_required' => false,
        ]);
    Attribute::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'paint_code',
        'name' => 'Paint Code',
    ]);
    Attribute::factory()->create([
        'company_id' => $user->company_id,
        'category_id' => $category->id,
        'product_template_id' => null,
        'code' => 'condition_grade',
        'name' => 'Condition Grade',
        'is_required' => true,
    ]);
    Item::factory()->create([
        'company_id' => $user->company_id,
        'category_id' => $category->id,
        'product_template_id' => $template->id,
    ]);

    Livewire::test(Index::class)
        ->assertSee(CATALOG_TEST_ATTRIBUTE_NAME)
        ->set('search', 'paint')
        ->assertSee('Paint Code')
        ->assertDontSee(CATALOG_TEST_ATTRIBUTE_NAME)
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
        ->set('search', '')
        ->call('setTab', 'templates')
        ->assertSet('sortBy', 'name')
        ->call('sort', 'category_name')
        ->assertSet('sortBy', 'category_name')
        ->assertSee('Template attrs')
        ->assertSee('Manage attributes')
        ->assertSee('Create item')
        ->assertSee('3')
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

test('catalog template actions focus attribute work on the selected template', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $category = Category::factory()->create([
        'company_id' => $user->company_id,
        'name' => CATALOG_TEST_CATEGORY_NAME,
    ]);
    $template = ProductTemplate::factory()
        ->forCategory($category)
        ->create(['name' => CATALOG_TEST_TEMPLATE_NAME]);

    Livewire::test(Index::class)
        ->call('setTab', 'templates')
        ->call('manageTemplateAttributes', $template->id)
        ->assertSet('tab', 'attributes')
        ->assertSet('filterTemplateId', (string) $template->id)
        ->call('addTemplateAttribute', $template->id)
        ->assertSet('tab', 'attributes')
        ->assertSet('attributeCategoryId', $category->id)
        ->assertSet('attributeProductTemplateId', $template->id)
        ->assertSet('showCreateModal', true);
});
