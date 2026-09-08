<?php

use App\Base\Integration\Livewire\OutboundExchanges\Index;
use App\Base\Integration\Livewire\OutboundExchanges\Show;
use App\Base\Integration\Models\OutboundExchange;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function outboundTenantExchange(?int $tenantId): OutboundExchange
{
    return OutboundExchange::query()->forceCreate([
        'tenant_id' => $tenantId,
        'system' => 'tenant-fixture', 'operation' => 'tenant-isolation',
        'endpoint' => 'https://example.invalid/private', 'outcome' => 'success',
        'occurred_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->actingAs(createAdminUser());
    [$tenant] = createTenantWithCompany(['name' => 'Exchange viewer tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
});

it('hides another tenant and legacy outbound exchanges from the index', function (): void {
    $own = outboundTenantExchange(app(TenantContext::class)->requireTenantId());
    [$foreignTenant] = createTenantWithCompany(['name' => 'Foreign exchange tenant']);
    $foreign = outboundTenantExchange((int) $foreignTenant->id);
    $legacy = outboundTenantExchange(null);
    Livewire::test(Index::class)->assertSee($own->id)->assertDontSee($foreign->id)->assertDontSee($legacy->id);
});

it('refuses to open a foreign exchange on the show page', function (): void {
    $foreign = outboundTenantExchange(null);
    expect(fn () => Livewire::test(Show::class, ['exchange' => $foreign]))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses deleteExchange for a foreign exchange and leaves the row', function (): void {
    $foreign = outboundTenantExchange(null);
    expect(fn () => Livewire::test(Index::class)->call('deleteExchange', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh())->not->toBeNull();
});
