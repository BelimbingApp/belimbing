<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

const REAP_ORPHAN_SESSION = 'sess_reap_orphan_test';

function createReapOrphanFixture(): int
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    return User::factory()->create(['company_id' => Company::LICENSEE_ID])->id;
}

describe('ReapOrphanRunsCommand', function () {
    beforeEach(function () {
        $this->actingForUserId = createReapOrphanFixture();
    });

    it('fails old running runs with no fresh progress events', function () {
        $run = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => REAP_ORPHAN_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Running,
            'current_phase' => RunPhase::RunningTool,
            'started_at' => now()->subMinutes(25),
        ]);

        $this->artisan('blb:ai:runs:reap-orphans', ['--threshold-seconds' => 1200])
            ->assertSuccessful()
            ->expectsOutputToContain('Reaped 1 orphaned run');

        $run->refresh();
        expect($run->status)->toBe(AiRunStatus::Failed);

        $failEvent = AiRunEvent::query()
            ->where('run_id', $run->id)
            ->where('event_type', RunEventType::RunFailed->value)
            ->first();

        expect($failEvent)->not->toBeNull()
            ->and($failEvent->payload['error_type'])->toBe('orphaned');
    });

    it('does not fail old running runs with fresh progress events', function () {
        $run = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => REAP_ORPHAN_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Running,
            'current_phase' => RunPhase::AwaitingLlm,
            'started_at' => now()->subMinutes(25),
        ]);

        AiRunEvent::query()->create([
            'run_id' => $run->id,
            'seq' => 1,
            'event_type' => RunEventType::Heartbeat,
            'payload' => ['elapsed_ms' => 1],
            'created_at' => now()->subMinute(),
        ]);

        $this->artisan('blb:ai:runs:reap-orphans', ['--threshold-seconds' => 1200])
            ->assertSuccessful()
            ->expectsOutputToContain('No orphaned runs found');

        expect($run->fresh()->status)->toBe(AiRunStatus::Running);
    });
});
