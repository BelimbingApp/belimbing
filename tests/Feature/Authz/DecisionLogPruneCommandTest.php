<?php

use App\Base\Authz\Models\DecisionLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('deletes decision logs older than retention and reports the count', function (): void {
    config(['authz.decision_log_retention_days' => 90]);

    $oldId = (int) DB::table('base_authz_decision_logs')->insertGetId([
        'company_id' => null,
        'actor_type' => 'user',
        'actor_id' => 1,
        'capability' => 'admin.test',
        'allowed' => true,
        'reason_code' => 'ok',
        'occurred_at' => now()->subDays(91)->toDateTimeString(),
    ]);
    $freshId = (int) DB::table('base_authz_decision_logs')->insertGetId([
        'company_id' => null,
        'actor_type' => 'user',
        'actor_id' => 1,
        'capability' => 'admin.test',
        'allowed' => true,
        'reason_code' => 'ok',
        'occurred_at' => now()->subDays(89)->toDateTimeString(),
    ]);

    // Guard: where(occurred_at < cutoff). Removing it deletes both rows.
    Artisan::call('blb:authz:decision-logs:prune');
    expect(Artisan::output())->toContain('Deleted 1')
        ->and(DecisionLog::query()->find($oldId))->toBeNull()
        ->and(DecisionLog::query()->find($freshId))->not->toBeNull();
});

it('dry-runs decision log prune without deleting', function (): void {
    config(['authz.decision_log_retention_days' => 90]);

    $id = (int) DB::table('base_authz_decision_logs')->insertGetId([
        'company_id' => null,
        'actor_type' => 'user',
        'actor_id' => 1,
        'capability' => 'admin.test',
        'allowed' => true,
        'reason_code' => 'ok',
        'occurred_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    Artisan::call('blb:authz:decision-logs:prune', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('Would delete 1')
        ->and(DecisionLog::query()->find($id))->not->toBeNull();
});
