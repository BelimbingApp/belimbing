<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Carbon;

test('csv export streams sales rows for the requested window with accounting fields', function (): void {
    $user = createAdminUser();

    app(SettingsService::class)->set(
        'commerce.default_currency_code',
        'USD',
        Scope::company($user->company_id),
    );

    $lighting = Category::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Lighting (test)',
    ]);
    $headlight = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'EXP-HL-1',
        'title' => 'Headlight (test)',
        'category_id' => $lighting->id,
    ]);

    Sale::factory()->create([
        'company_id' => $user->company_id,
        'item_id' => $headlight->id,
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-04-10 10:00:00'),
        'quantity' => 1,
        'sale_amount' => 5000,
        'cost_basis_amount' => 1500,
        'fee_amount' => 400,
    ]);

    Sale::factory()->create([
        'company_id' => $user->company_id,
        'item_id' => null,
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-05-05 10:00:00'),
        'sale_amount' => 99999,
    ]);

    $response = $this->actingAs($user)
        ->get(route('commerce.sales.export.csv', [
            'from' => '2026-04-01',
            'to' => '2026-04-30',
            'currency_code' => 'USD',
        ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="sales-export-2026-04-01-to-2026-04-30-usd.csv"');

    $body = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", $body)));

    expect($lines[0])->toContain('sold_at,channel,external_sale_id')
        ->and($lines[0])->toContain('gross_profit,currency_code')
        ->and($lines)->toHaveCount(2)
        ->and($lines[1])->toContain('Headlight (test)')
        ->and($lines[1])->toContain('Lighting (test)')
        ->and($lines[1])->toContain(',50.00,')
        ->and($lines[1])->toContain(',15.00,')
        ->and($lines[1])->toContain(',4.00,')
        ->and($lines[1])->toContain(',31.00,')
        ->and($lines[1])->toContain('USD')
        ->and($body)->not->toContain('999.99');
});

test('csv export rejects an inverted date range', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('commerce.sales.export.csv', [
            'from' => '2026-04-30',
            'to' => '2026-04-01',
            'currency_code' => 'USD',
        ]))
        ->assertStatus(422);
});
