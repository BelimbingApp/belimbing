<?php

use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

const SWEEP_STALE_SESSION = 'sess_stale_test';

const SWEEP_EXPECT_ONE_STALE_LINE = '1 stale turn';

function createSweepFixture(): int
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    return User::factory()->create(['company_id' => Company::LICENSEE_ID])->id;
}

describe('SweepStaleTurnsCommand', function () {
    beforeEach(function () {
        $this->actingForUserId = createSweepFixture();
    });

    it('fails queued turns past the threshold', function () {
        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Queued,
            'current_phase' => RunPhase::WaitingForWorker,
        ]);

        AiRun::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(15),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain(SWEEP_EXPECT_ONE_STALE_LINE);

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);
    });

    it('fails booting turns past the queued threshold', function () {
        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Booting,
            'current_phase' => RunPhase::AwaitingLlm,
        ]);

        AiRun::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(15),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain(SWEEP_EXPECT_ONE_STALE_LINE);

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);
    });

    it('fails running turns past the running threshold', function () {
        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Running,
            'current_phase' => RunPhase::RunningTool,
        ]);

        AiRun::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(45),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--running-minutes' => 30])
            ->assertSuccessful()
            ->expectsOutputToContain(SWEEP_EXPECT_ONE_STALE_LINE);

        $turn->refresh();
        expect($turn->status)->toBe(AiRunStatus::Failed);
    });

    it('does not touch turns within the threshold', function () {
        AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Queued,
            'current_phase' => RunPhase::WaitingForWorker,
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('No stale turns');
    });

    it('does not touch completed turns', function () {
        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Succeeded,
            'current_phase' => RunPhase::Finalizing,
        ]);

        AiRun::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(60),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('No stale turns');
    });

    it('emits run.failed event for each swept turn', function () {
        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => $this->actingForUserId,
            'status' => AiRunStatus::Queued,
            'current_phase' => RunPhase::WaitingForWorker,
        ]);

        AiRun::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(15),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful();

        $failEvent = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', 'run.failed')
            ->first();

        expect($failEvent)->not->toBeNull();
        expect($failEvent->payload['error_type'])->toBe('stale_queued');
        expect($failEvent->payload['message'])->toContain('No worker claimed');
    });
});
