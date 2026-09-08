<?php

use App\Base\Integration\Livewire\OutboundExchanges\Index;
use App\Base\Integration\Livewire\OutboundExchanges\Show;
use App\Base\Integration\Models\OutboundExchange;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
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

it('fails closed without an ambient tenant', function (): void {
    app(TenantContext::class)->clear();
    expect(fn () => OutboundExchange::visibleToCurrentTenant()->count())
        ->toThrow(TenantContextMissingException::class);
});

it('allows platform operators to inspect legacy rows', function (): void {
    createAdminUser();
    $legacy = outboundTenantExchange(null);
    expect(OutboundExchange::visibleToCurrentTenant()->find($legacy->id)?->id)->toBe($legacy->id);
});

it('stamps outbound records with the current tenant', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenantId = app(TenantContext::class)->requireTenantId();
    app(IntegrationGateway::class)->send(
        new IntegrationRequest(
            system: 'fixture', operation: 'tenant.stamp', method: 'GET', endpoint: 'https://example.invalid/stamp',
        ),
    );
    expect(OutboundExchange::query()->where('operation', 'tenant.stamp')->firstOrFail()->tenant_id)->toBe($tenantId);
});

it('cleans only the current tenant payloads from the UI', function (): void {
    $own = outboundTenantExchange(app(TenantContext::class)->requireTenantId());
    $foreign = outboundTenantExchange(null);
    foreach ([$own, $foreign] as $exchange) {
        $exchange->update(['occurred_at' => now()->subDays(40), 'request_body' => ['secret' => 'retained']]);
    }
    Livewire::test(Index::class)->call('cleanupPayloads');
    expect($own->fresh()->request_body)->toBeNull()
        ->and($foreign->fresh()->request_body)->toBe(['secret' => 'retained']);
});
