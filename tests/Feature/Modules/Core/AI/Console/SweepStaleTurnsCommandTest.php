<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;

const SWEEP_STALE_SESSION = 'sess_stale_test';

const SWEEP_EXPECT_ONE_STALE_LINE = '1 stale turn';

function createSweepFixture(): void
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();
}

describe('SweepStaleTurnsCommand', function () {
    beforeEach(function () {
        createSweepFixture();
    });

    it('fails queued turns past the threshold', function () {
        $turn = ChatTurn::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => 1,
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
        ]);

        ChatTurn::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(15),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain(SWEEP_EXPECT_ONE_STALE_LINE);

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);
    });

    it('fails booting turns past the queued threshold', function () {
        $turn = ChatTurn::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => 1,
            'status' => TurnStatus::Booting,
            'current_phase' => TurnPhase::Thinking,
        ]);

        ChatTurn::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(15),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain(SWEEP_EXPECT_ONE_STALE_LINE);

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);
    });

    it('fails running turns past the running threshold', function () {
        $turn = ChatTurn::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => 1,
            'status' => TurnStatus::Running,
            'current_phase' => TurnPhase::RunningTool,
        ]);

        ChatTurn::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(45),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--running-minutes' => 30])
            ->assertSuccessful()
            ->expectsOutputToContain(SWEEP_EXPECT_ONE_STALE_LINE);

        $turn->refresh();
        expect($turn->status)->toBe(TurnStatus::Failed);
    });

    it('does not touch turns within the threshold', function () {
        ChatTurn::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => 1,
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('No stale turns');
    });

    it('does not touch completed turns', function () {
        $turn = ChatTurn::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => 1,
            'status' => TurnStatus::Completed,
            'current_phase' => TurnPhase::Finalizing,
        ]);

        ChatTurn::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(60),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('No stale turns');
    });

    it('emits turn.failed event for each swept turn', function () {
        $turn = ChatTurn::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => SWEEP_STALE_SESSION,
            'acting_for_user_id' => 1,
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
        ]);

        ChatTurn::query()->where('id', $turn->id)->update([
            'created_at' => now()->subMinutes(15),
        ]);

        $this->artisan('blb:ai:turns:sweep-stale', ['--queued-minutes' => 10])
            ->assertSuccessful();

        $failEvent = ChatTurnEvent::query()
            ->where('turn_id', $turn->id)
            ->where('event_type', 'turn.failed')
            ->first();

        expect($failEvent)->not->toBeNull();
        expect($failEvent->payload['error_type'])->toBe('stale_queued');
        expect($failEvent->payload['message'])->toContain('No worker claimed');
    });
});
