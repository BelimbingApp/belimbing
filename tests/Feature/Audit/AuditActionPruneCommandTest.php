<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('deletes old non-retained actions and keeps retained and fresh rows', function (): void {
    config(['audit.action_retention_days' => 90]);

    $retainedId = (int) DB::table('base_audit_actions')->insertGetId([
        'company_id' => null,
        'tenant_id' => 1,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'event' => 'http.request',
        'payload' => json_encode(['status' => 200]),
        'trace_id' => 'PRUNEKEEP0001',
        'is_retained' => true,
        'occurred_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $oldId = (int) DB::table('base_audit_actions')->insertGetId([
        'company_id' => null,
        'tenant_id' => 1,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'event' => 'http.request',
        'payload' => json_encode(['status' => 200]),
        'trace_id' => 'PRUNEOLD00001',
        'is_retained' => false,
        'occurred_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $freshId = (int) DB::table('base_audit_actions')->insertGetId([
        'company_id' => null,
        'tenant_id' => 1,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'event' => 'http.request',
        'payload' => json_encode(['status' => 200]),
        'trace_id' => 'PRUNEFRESH0001',
        'is_retained' => false,
        'occurred_at' => now()->subDays(89)->toDateTimeString(),
    ]);

    Artisan::call('blb:audit:actions:prune');
    expect(AuditAction::query()->find($retainedId))->not->toBeNull()
        ->and(AuditAction::query()->find($oldId))->toBeNull()
        ->and(AuditAction::query()->find($freshId))->not->toBeNull();
});

it('prunes across tenants with no ambient tenant and ignores a later ambient set', function (): void {
    config(['audit.action_retention_days' => 90]);

    $a = (int) DB::table('base_audit_actions')->insertGetId([
        'company_id' => null,
        'tenant_id' => 10,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'event' => 'http.request',
        'payload' => json_encode(['status' => 200]),
        'trace_id' => 'PRUNETENA0001',
        'is_retained' => false,
        'occurred_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $b = (int) DB::table('base_audit_actions')->insertGetId([
        'company_id' => null,
        'tenant_id' => 20,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'event' => 'http.request',
        'payload' => json_encode(['status' => 200]),
        'trace_id' => 'PRUNETENB0001',
        'is_retained' => false,
        'occurred_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $inside = (int) DB::table('base_audit_actions')->insertGetId([
        'company_id' => null,
        'tenant_id' => 20,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'event' => 'http.request',
        'payload' => json_encode(['status' => 200]),
        'trace_id' => 'PRUNETENBINS1',
        'is_retained' => false,
        'occurred_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    app(TenantContext::class)->set(10);
    app(TenantContext::class)->clear();

    Artisan::call('blb:audit:actions:prune');
    expect(AuditAction::query()->find($a))->toBeNull()
        ->and(AuditAction::query()->find($b))->toBeNull()
        ->and(AuditAction::query()->find($inside))->not->toBeNull()
        ->and(app(TenantContext::class)->currentTenantId())->toBeNull();
});
