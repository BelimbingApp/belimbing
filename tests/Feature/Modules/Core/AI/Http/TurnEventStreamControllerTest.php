<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

const REPLAY_TEST_SESSION = 'sess_replay_test';
const REPLAY_TEST_RUN = 'run_replay_001';

/**
 * @return array{user: User, employee: Employee}
 */
function createReplayFixture(): array
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    return [
        'user' => User::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
        ]),
        'employee' => Employee::query()->findOrFail(Employee::LARA_ID),
    ];
}

function createTurnWithReplayEvents(int $userId): ChatTurn
{
    $turn = ChatTurn::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => REPLAY_TEST_SESSION,
        'acting_for_user_id' => $userId,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
    ]);

    $pub = app(TurnEventPublisher::class);

    $pub->turnStarted($turn);
    $pub->runStarted($turn, REPLAY_TEST_RUN);
    $turn->transitionTo(TurnStatus::Running);
    $pub->phaseChanged($turn, TurnPhase::AwaitingLlm, TurnPhase::AwaitingLlm->label());
    $pub->thinkingStarted($turn);
    $pub->phaseChanged($turn, TurnPhase::RunningTool, 'bash');
    $pub->toolStarted($turn, 'bash', '{"cmd":"ls"}', 0);
    $pub->toolFinished($turn, 'bash', 'success', '10 files', 150, 32);
    $pub->phaseChanged($turn, TurnPhase::StreamingAnswer, 'Responding…');
    $pub->outputDelta($turn, 'Hello world');
    $pub->outputBlockCommitted($turn, 'markdown', 'Hello world');
    $pub->turnCompleted($turn, ['elapsed_ms' => 1200]);

    return $turn->refresh();
}

describe('TurnEventStreamController', function () {
    it('returns all events for a completed turn as JSON', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
            'after_seq' => 0,
        ]));

        $response->assertOk();
        $json = $response->json();

        expect($json)->toHaveKey('events');
        expect($json)->toHaveKey('turn_id', $turn->id);
        expect($json)->toHaveKey('status', 'completed');

        $eventTypes = collect($json['events'])->pluck('event_type')->all();

        expect($eventTypes)
            ->toContain('turn.started')
            ->toContain('run.started')
            ->toContain('turn.phase_changed')
            ->toContain('assistant.thinking_started')
            ->toContain('tool.started')
            ->toContain('tool.finished')
            ->toContain('assistant.output_delta')
            ->toContain('assistant.output_block_committed')
            ->toContain('turn.completed');
    });

    it('resumes from after_seq skipping earlier events', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
            'after_seq' => 5,
        ]));

        $response->assertOk();
        $json = $response->json();

        $eventTypes = collect($json['events'])->pluck('event_type')->all();

        expect($eventTypes)
            ->not->toContain('turn.started')
            ->not->toContain('run.started')
            ->toContain('tool.started')
            ->toContain('tool.finished')
            ->toContain('turn.completed');
    });

    it('returns 404 for non-existent turn', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => '01NONEXISTENT000000000000000',
        ]));

        $response->assertNotFound();
    });

    it('returns 403 for turn belonging to another user', function () {
        $fixture = createReplayFixture();

        $otherUser = User::factory()->create([
            'company_id' => Company::LICENSEE_ID,
            'employee_id' => $fixture['employee']->id,
        ]);

        $turn = createTurnWithReplayEvents($otherUser->id);

        $this->actingAs($fixture['user']);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
        ]));

        $response->assertForbidden();
    });

    it('includes seq and turn_id in every event', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
        ]));

        $json = $response->json();
        $events = $json['events'];

        expect(count($events))->toBeGreaterThanOrEqual(10);

        foreach ($events as $event) {
            expect($event['turn_id'])->toBe($turn->id);
            expect($event)->toHaveKey('seq');
            expect($event)->toHaveKey('event_type');
            expect($event)->toHaveKey('occurred_at');
        }
    });

    it('returns turn metadata in response envelope', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
        ]));

        $json = $response->json();

        expect($json)->toHaveKey('turn_id', $turn->id);
        expect($json)->toHaveKey('status', 'completed');
        expect($json)->toHaveKey('started_at');
        expect($json)->toHaveKey('created_at');
    });
});
