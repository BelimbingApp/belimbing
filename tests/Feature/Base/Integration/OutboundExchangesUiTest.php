<?php

use App\Base\Integration\Livewire\OutboundExchanges\Index;
use App\Base\Integration\Models\OutboundExchange;

it('lists and shows outbound exchanges with retained payloads', function (): void {
    $user = createAdminUser();
    $exchange = OutboundExchange::query()->create([
        'system' => 'example',
        'provider' => 'provider.example',
        'operation' => 'example.operation',
        'transport' => 'http',
        'protocol' => 'rest',
        'protocol_operation' => 'GET /things',
        'endpoint' => 'https://provider.example/things',
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
        ->assertSee('Success')
        ->assertSee('Retained')
        ->assertSee('hello')
        ->assertSee('world')
        ->assertSee('Copy payload')
        ->assertSee('Completed with a non-error response.')
        ->assertSee('Retained payloads are removed by retention cleanup.');
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
        'endpoint' => 'https://provider.example/things',
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
        'endpoint' => 'https://api.sandbox.ebay.com/sell/account/v1/location',
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
