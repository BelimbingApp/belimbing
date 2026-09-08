<?php

use App\Base\Database\Models\DataSharePlan;
use App\Base\Database\Models\DataSharePlanAction;
use App\Base\Database\Models\DataShareReceipt;
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
    // Pass un-cast numerics as strings so an accidental skip of reload cannot
    // satisfy the post-reload assertions from the in-memory create payload.
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
        'bytes' => '4096',
        'metadata' => ['probe' => true],
        'status' => 'active',
        'expires_at' => now()->addDay(),
        'download_count' => '3',
        'max_downloads' => 9,
    ]);

    expect(get_debug_type($offer->bytes))->toBe('string')
        ->and(get_debug_type($offer->download_count))->toBe('string');

    $reloaded = DataShareTransferOffer::query()->findOrFail($offer->id);
    $reloaded->makeVisible(['secret']);

    expect(get_debug_type($reloaded->id))->toBe('int')
        ->and(get_debug_type($reloaded->bytes))->toBe('int')
        ->and(get_debug_type($reloaded->download_count))->toBe('int')
        ->and(get_debug_type($reloaded->max_downloads))->toBe('int')
        ->and(get_debug_type($reloaded->secret))->toBe('string')
        ->and($reloaded->getConnection()->getDriverName())->toBeIn(['sqlite', 'pgsql']);
});

it('hydrates plan receipt_id and plan action sequence as native int after reload', function (): void {
    $receipt = DataShareReceipt::query()->create([
        'package_id' => (string) Str::ulid(),
        'package_sha256' => hash('sha256', 'pkg'),
        'package_path' => 'packages/probe.zip',
        'source_instance_id' => 'src',
        'source_role' => 'source',
        'target_instance_id' => 'dst',
        'scope_name' => 'probe.scope',
        'offer_id' => (string) Str::ulid(),
        'status' => 'received',
        'received_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
    $plan = DataSharePlan::query()->create([
        'receipt_id' => (string) $receipt->id,
        'plan_hash' => hash('sha256', 'plan'),
        'package_sha256' => hash('sha256', 'pkg'),
        'destination_fingerprint' => hash('sha256', 'dst'),
        'summary' => ['n' => 1],
        'status' => 'planned',
        'planned_at' => now(),
    ]);
    $action = DataSharePlanAction::query()->create([
        'plan_id' => $plan->id,
        'sequence' => '7',
        'scope_name' => 'probe.scope',
        'table_name' => 'probe_table',
        'primary_key_hash' => hash('sha256', 'pk'),
        'primary_key' => ['id' => 1],
        'action' => 'insert',
        'incoming_fingerprint' => hash('sha256', 'in'),
    ]);

    expect(get_debug_type($plan->receipt_id))->toBe('string')
        ->and(get_debug_type($action->sequence))->toBe('string');

    $plan = DataSharePlan::query()->findOrFail($plan->id);
    $action = DataSharePlanAction::query()->findOrFail($action->id);

    expect(get_debug_type($plan->receipt_id))->toBe('int')
        ->and(get_debug_type($action->sequence))->toBe('int');
});
