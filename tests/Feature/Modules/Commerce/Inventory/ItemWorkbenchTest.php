<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Create;
use App\Modules\Commerce\Inventory\Models\Item;
use Livewire\Livewire;

test('guests are redirected to login from inventory item pages', function (): void {
    $this->get(route('commerce.inventory.items.index'))->assertRedirect(route('login'));
    $this->get(route('commerce.inventory.items.create'))->assertRedirect(route('login'));
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
