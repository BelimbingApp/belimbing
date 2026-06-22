<?php

use App\Base\Media\PhotoCleanup\Contracts\TestsConnection;
use App\Base\Media\PhotoCleanup\PhotoCleanupConnectionTester;
use App\Base\Media\PhotoCleanup\PhotoRoomClient;
use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Facades\Http;

const PHOTOROOM_ACCOUNT_ENDPOINT = 'https://image-api.photoroom.com/*';

const PHOTOROOM_SEGMENT_ENDPOINT = 'https://sdk.photoroom.com/v1/segment';

const PHOTOROOM_ACCOUNT_ENDPOINT_V2 = 'https://image-api.photoroom.com/v2/account';

const PHOTOROOM_ACCOUNT_ENDPOINT_V1 = 'https://image-api.photoroom.com/v1/account';

beforeEach(function (): void {
    $this->companyId = configurePhotoRoom('sandbox-key-123');
});

it('verifies a working photoroom key against /v2/account', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT => Http::response([
            'images' => ['available' => 83, 'subscription' => 100],
            'plan' => 'basic',
        ], 200),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeTrue()
        ->and($result->label)->toBe('Connected')
        ->and($result->detail)->toContain('Plan: basic')
        ->and($result->detail)->toContain('83 of 100')
        ->and($result->context)->toHaveKey('plan')
        ->and($result->context['plan'])->toBe('basic')
        ->and($result->context['available'])->toBe(83)
        ->and($result->context['subscription'])->toBe(100);
});

it('reports no key stored when the company has no photoroom key', function (): void {
    $companyId = Company::factory()->create()->id;

    $result = app(PhotoRoomClient::class)->testConnection($companyId);

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('No key stored')
        ->and($result->detail)->toContain('Add a key');
});

it('reports unauthorized when the photoroom key is rejected (401)', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT => Http::response('{"error":"unauthorized"}', 401),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('Unauthorized')
        ->and($result->detail)->toContain('rejected');
});

it('reports request failed on a non-401 error', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT => Http::response('boom', 500),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('Request failed')
        ->and($result->detail)->toContain('500')
        ->and($result->context['status'])->toBe(500);
});

it('still succeeds when the account response omits credit fields', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT => Http::response(['plan' => 'basic'], 200),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeTrue()
        ->and($result->detail)->toContain('Plan: basic')
        ->and($result->context)->toBe(['plan' => 'basic']);
});

it('exposes the photoroom provider key through TestsConnection', function (): void {
    expect(app(PhotoRoomClient::class))
        ->toBeInstanceOf(TestsConnection::class)
        ->and(app(PhotoRoomClient::class)->providerKey())->toBe(PhotoRoomConfiguration::PROVIDER);
});

it('tester supports the bound provider key only', function (): void {
    $tester = app(PhotoCleanupConnectionTester::class);

    expect($tester->supports(PhotoRoomConfiguration::PROVIDER))->toBeTrue()
        ->and($tester->supports('claid'))->toBeFalse()
        ->and($tester->supports('poof'))->toBeFalse();
});

it('tester returns no handshake for a provider without a bound TestsConnection client', function (): void {
    $result = app(PhotoCleanupConnectionTester::class)->test($this->companyId, 'claid');

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('No handshake available')
        ->and($result->detail)->toContain('claid')
        ->and($result->context['provider'])->toBe('claid');
});

it('tester dispatches to the bound photoroom client for its own key', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT => Http::response([
            'images' => ['available' => 10, 'subscription' => 100],
            'plan' => 'basic',
        ], 200),
    ]);

    $result = app(PhotoCleanupConnectionTester::class)->test($this->companyId, PhotoRoomConfiguration::PROVIDER);

    expect($result->ok)->toBeTrue()
        ->and($result->label)->toBe('Connected')
        ->and($result->context['available'])->toBe(10);
});

it('verifies a sandbox key via a minimal probe edit on /v1/segment', function (): void {
    configurePhotoRoom('sandbox_testkey123', $this->companyId);

    Http::fake([
        PHOTOROOM_SEGMENT_ENDPOINT => Http::response('PROBE-PNG-BYTES', 200, ['Content-Type' => 'image/png']),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeTrue()
        ->and($result->label)->toBe('Connected')
        ->and($result->detail)->toContain('probe edit')
        ->and($result->detail)->toContain('sandbox')
        ->and($result->context)->toHaveKey('sandbox')
        ->and($result->context['sandbox'])->toBeTrue();

    // The sandbox branch must probe the cleanup endpoint, not the account host.
    Http::assertSentCount(1);
});

it('reports unauthorized when the sandbox probe key is rejected', function (): void {
    configurePhotoRoom('sandbox_testkey123', $this->companyId);

    Http::fake([
        PHOTOROOM_SEGMENT_ENDPOINT => Http::response('{"error":"unauthorized"}', 401),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('Unauthorized')
        ->and($result->detail)->toContain('rejected');
});

it('reports request failed when the sandbox probe errors with a non-auth status', function (): void {
    configurePhotoRoom('sandbox_testkey123', $this->companyId);

    Http::fake([
        PHOTOROOM_SEGMENT_ENDPOINT => Http::response('{"detail":"invalid_image"}', 400),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('Request failed')
        ->and($result->detail)->toContain('400')
        ->and($result->context['status'])->toBe(400);
});

it('falls back to /v1/account when /v2/account rejects a legacy pricing version', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT_V2 => Http::response([
            'error' => ['message' => 'You are using an old pricing version. Please contact support.'],
        ], 400),
        PHOTOROOM_ACCOUNT_ENDPOINT_V1 => Http::response([
            'credits' => ['available' => 50, 'subscription' => 100],
        ], 200),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeTrue()
        ->and($result->label)->toBe('Connected')
        ->and($result->detail)->toContain('50 of 100')
        ->and($result->context['available'])->toBe(50)
        ->and($result->context['subscription'])->toBe(100)
        ->and($result->context)->not->toHaveKey('plan');
});

it('reports request failed when the /v1/account fallback also fails', function (): void {
    Http::fake([
        PHOTOROOM_ACCOUNT_ENDPOINT_V2 => Http::response(['error' => ['message' => 'old pricing']], 400),
        PHOTOROOM_ACCOUNT_ENDPOINT_V1 => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    $result = app(PhotoRoomClient::class)->testConnection($this->companyId);

    expect($result->ok)->toBeFalse()
        ->and($result->label)->toBe('Request failed')
        ->and($result->detail)->toContain('500')
        ->and($result->context['status'])->toBe(500);
});
