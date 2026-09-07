<?php

use App\Base\Database\Models\DataShareTransferOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Records hydrated PHP types for columns that `@property` blocks must name.
 *
 * Rule: docs/architecture/model-property-annotations.md.
 * Assertions are driver-agnostic so sqlite (phpunit default) and pgsql
 * (postgres-mirror CI) both prove the same native types.
 */
it('hydrates un-cast integers and encrypted secrets as native PHP types after reload', function (): void {
    $offer = DataShareTransferOffer::query()->create([
        'offer_id' => (string) Str::ulid(),
        'secret_hash' => hash('sha256', 'probe-secret'),
        'secret' => 'probe-secret-value',
        'package_id' => (string) Str::ulid(),
        'package_sha256' => hash('sha256', 'pkg'),
        'package_path' => 'packages/probe.zip',
        'source_instance_id' => 'probe-source',
        'source_name' => 'Probe',
        'source_role' => 'source',
        'scope_name' => 'probe.scope',
        'bytes' => 4096,
        'metadata' => ['probe' => true],
        'status' => 'active',
        'expires_at' => now()->addDay(),
        'download_count' => 3,
        'max_downloads' => 9,
    ]);

    $reloaded = DataShareTransferOffer::query()->findOrFail($offer->id);
    $reloaded->makeVisible(['secret']);

    expect(get_debug_type($reloaded->id))->toBe('int')
        ->and(get_debug_type($reloaded->bytes))->toBe('int')
        ->and(get_debug_type($reloaded->download_count))->toBe('int')
        ->and(get_debug_type($reloaded->max_downloads))->toBe('int')
        ->and(get_debug_type($reloaded->secret))->toBe('string')
        ->and($reloaded->getConnection()->getDriverName())->toBeIn(['sqlite', 'pgsql']);
});
