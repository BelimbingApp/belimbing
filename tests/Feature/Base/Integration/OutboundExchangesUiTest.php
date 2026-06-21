<?php

use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Livewire\AuditLog\SourceHistory;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Integration\Livewire\OutboundExchanges\Index;
use App\Base\Integration\Models\OutboundExchange;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;

const OUTBOUND_EXCHANGES_UI_ENDPOINT = 'https://provider.example/things';

function outboundExchangesUiFlushAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

it('lists and shows outbound exchanges with retained payloads', function (): void {
    $user = createAdminUser();
    $exchange = OutboundExchange::query()->create([
        'system' => 'example',
        'provider' => 'provider.example',
        'operation' => 'example.operation',
        'transport' => 'http',
        'protocol' => 'rest',
        'protocol_operation' => 'GET /things',
        'endpoint' => OUTBOUND_EXCHANGES_UI_ENDPOINT,
        'owner_type' => 'company',
        'owner_id' => $user->company_id,
        'request_headers' => ['X-Test' => ['1']],
        'request_body' => ['kind' => 'json', 'value' => ['hello' => 'world']],
        'response_status' => 200,
        'response_body' => ['kind' => 'json', 'value' => ['ok' => true]],
        'duration_ms' => 12,
        'retry_count' => 0,
        'outcome' => 'success',
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.integration.outbound-exchanges.index'))
        ->assertOk()
        ->assertSee($exchange->id)
        ->assertSee('example.operation');

    $this->actingAs($user)
        ->get(route('admin.integration.outbound-exchanges.show', $exchange))
        ->assertOk()
        ->assertSee($exchange->id)
        ->assertSee('History')
        ->assertSeeHtml('wire:click="open"')
        ->assertSee('Success')
        ->assertSee('Retained')
        ->assertSee('hello')
        ->assertSee('world')
        ->assertSee('Completed with a non-error response.')
        ->assertSee('Retained payloads are removed by retention cleanup.');

    expect($exchange->getAuditSubject())->toBe(['name' => 'outbound_exchange', 'id' => $exchange->id]);
});

it('shows exchange record history without leaking retained payloads', function (): void {
    [$company, $viewer] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->create();
        $viewer = User::factory()->create(['company_id' => $company->id]);

        return [$company, $viewer];
    });

    foreach (['admin.audit.log.list', 'admin.system.outbound-exchange.list'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $viewer->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    $exchange = OutboundExchange::query()->create([
        'system' => 'example',
        'provider' => 'provider.example',
        'operation' => 'example.secret_safe.operation',
        'transport' => 'http',
        'protocol' => 'rest',
        'protocol_operation' => 'POST /things',
        'endpoint' => OUTBOUND_EXCHANGES_UI_ENDPOINT,
        'request_headers' => ['Authorization' => ['Bearer hidden-header-token']],
        'request_body' => ['kind' => 'json', 'value' => ['secret' => 'hidden-request-secret']],
        'response_status' => 200,
        'response_body' => ['kind' => 'json', 'value' => ['secret' => 'hidden-response-secret']],
        'metadata' => ['secret' => 'hidden-metadata-secret'],
        'duration_ms' => 12,
        'retry_count' => 0,
        'outcome' => 'success',
        'occurred_at' => now(),
    ]);

    outboundExchangesUiFlushAuditBuffer();

    Livewire\Livewire::actingAs($viewer)
        ->test(SourceHistory::class, [
            'title' => __('History for exchange :id', ['id' => $exchange->id]),
            'subjects' => [['name' => 'outbound_exchange', 'id' => $exchange->id]],
            'auditableType' => $exchange->getMorphClass(),
            'auditableId' => $exchange->id,
            'sourceCapability' => 'admin.system.outbound-exchange.list',
        ])
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', true)
        ->assertSee('example.secret_safe.operation')
        ->assertSee('outcome')
        ->assertDontSee('hidden-header-token')
        ->assertDontSee('hidden-request-secret')
        ->assertDontSee('hidden-response-secret')
        ->assertDontSee('hidden-metadata-secret');
});

it('shows explanatory tooltips for truncated payloads and HTTP errors', function (): void {
    $user = createAdminUser();
    $exchange = OutboundExchange::query()->create([
        'system' => 'example',
        'provider' => 'provider.example',
        'operation' => 'example.operation',
        'transport' => 'http',
        'protocol' => 'rest',
        'protocol_operation' => 'POST /things',
        'endpoint' => OUTBOUND_EXCHANGES_UI_ENDPOINT,
        'owner_type' => 'company',
        'owner_id' => $user->company_id,
        'request_body' => ['kind' => 'json', 'value' => ['hello' => 'world']],
        'request_body_truncated' => true,
        'response_status' => 422,
        'response_body' => ['kind' => 'json', 'value' => ['error' => 'invalid']],
        'response_body_truncated' => true,
        'duration_ms' => 12,
        'retry_count' => 0,
        'outcome' => 'http_error',
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.integration.outbound-exchanges.show', $exchange))
        ->assertOk()
        ->assertSee('HTTP error')
        ->assertSee('Truncated')
        ->assertSee('The external system returned an HTTP error.')
        ->assertSee('Stored as a shortened preview.');
});

it('cleans retained payloads according to retention policy', function (): void {
    $user = createAdminUser();
    $exchange = OutboundExchange::query()->create([
        'system' => 'ebay',
        'provider' => 'ebay',
        'operation' => 'commerce.marketplace.ebay.locations.pull',
        'transport' => 'http',
        'protocol' => 'rest',
        'endpoint' => 'https://api.sandbox.ebay.com/sell/inventory/v1/location',
        'request_headers' => ['Authorization' => ['[redacted]']],
        'request_body' => ['kind' => 'json', 'value' => ['a' => 'b']],
        'response_status' => 200,
        'response_body' => ['kind' => 'json', 'value' => ['ok' => true]],
        'duration_ms' => 12,
        'retry_count' => 0,
        'outcome' => 'success',
        'occurred_at' => now()->subDays(31),
    ]);

    $this->actingAs($user)
        ->get(route('admin.integration.outbound-exchanges.index'))
        ->assertOk();

    Livewire\Livewire::actingAs($user)
        ->test(Index::class)
        ->call('cleanupPayloads')
        ->assertSet('statusVariant', 'success');

    $exchange->refresh();
    expect($exchange->request_body)->toBeNull()
        ->and($exchange->response_body)->toBeNull()
        ->and($exchange->request_headers)->toBeNull()
        ->and($exchange->response_headers)->toBeNull();
});
