<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Livewire\Items\Edit;
use App\Modules\Commerce\Inventory\Models\Item;
use Livewire\Livewire;

test('guests are redirected to login from inventory item pages', function (): void {
    $item = Item::factory()->create();

    $this->get(route('commerce.inventory.items.index'))->assertRedirect(route('login'));
    $this->get(route('commerce.inventory.items.create'))->assertRedirect(route('login'));
    $this->get(route('commerce.inventory.items.show', $item))->assertRedirect(route('login'));
    $this->get(route('commerce.inventory.items.edit', $item))->assertRedirect(route('login'));
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
        ->assertSee('Driver side headlight assembly')
        ->assertSee(route('commerce.inventory.items.show', Item::query()->where('sku', 'ITEM-TEST123')->first()));
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
        ->assertSee('MYR 950.00')
        ->assertSee('MYR 1,450.00');
});

test('item can be updated from the browser workbench component', function (): void {
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

    Livewire::test(Edit::class, ['item' => $item])
        ->set('title', 'Edited inventory item')
        ->set('description', 'Updated condition notes.')
        ->set('status', Item::STATUS_READY)
        ->set('unitCostAmount', '45.50')
        ->set('targetPriceAmount', '130.00')
        ->set('currencyCode', 'MYR')
        ->call('save')
        ->assertRedirect(route('commerce.inventory.items.show', $item));

    $item->refresh();

    expect($item->title)->toBe('Edited inventory item')
        ->and($item->description)->toBe('Updated condition notes.')
        ->and($item->status)->toBe(Item::STATUS_READY)
        ->and($item->unit_cost_amount)->toBe(4550)
        ->and($item->target_price_amount)->toBe(13000)
        ->and($item->currency_code)->toBe('MYR');
});

test('users cannot view or edit inventory items from another company', function (): void {
    $user = createAdminUser();
    $otherUser = createAdminUser();

    $item = Item::factory()->create([
        'company_id' => $otherUser->company_id,
    ]);

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.show', $item))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('commerce.inventory.items.edit', $item))
        ->assertNotFound();
});
