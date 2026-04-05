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

const RESUME_TEST_SESSION = 'sess_resume_test';
const RESUME_TEST_RUN = 'run_resume_001';

/**
 * @return array{user: User, employee: Employee}
 */
function createResumeFixture(): array
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

function createTurnWithEvents(int $userId): ChatTurn
{
    $turn = ChatTurn::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => RESUME_TEST_SESSION,
        'acting_for_user_id' => $userId,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
    ]);

    $pub = app(TurnEventPublisher::class);

    $pub->turnStarted($turn);
    $pub->runStarted($turn, RESUME_TEST_RUN);
    $turn->transitionTo(TurnStatus::Running);
    $pub->phaseChanged($turn, TurnPhase::Thinking, 'Thinking…');
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
    it('replays all events for a completed turn', function () {
        $fixture = createResumeFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithEvents($fixture['user']->id);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
            'after_seq' => 0,
        ]));

        $response->assertOk();
        $content = $response->streamedContent();

        // Should contain turn events as SSE
        expect($content)
            ->toContain('event: turn.started')
            ->toContain('event: run.started')
            ->toContain('event: turn.phase_changed')
            ->toContain('event: assistant.thinking_started')
            ->toContain('event: tool.started')
            ->toContain('event: tool.finished')
            ->toContain('event: assistant.output_delta')
            ->toContain('event: assistant.output_block_committed')
            ->toContain('event: turn.completed')
            ->toContain('event: meta');  // stream_end meta event
    });

    it('resumes from after_seq skipping earlier events', function () {
        $fixture = createResumeFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithEvents($fixture['user']->id);

        // Skip first 5 events (turn.started, run.started, phase.changed, thinking_started, phase.changed)
        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
            'after_seq' => 5,
        ]));

        $response->assertOk();
        $content = $response->streamedContent();

        // Should NOT contain early events
        expect($content)
            ->not->toContain('event: turn.started')
            ->not->toContain('event: run.started');

        // Should contain later events
        expect($content)
            ->toContain('event: tool.started')
            ->toContain('event: tool.finished')
            ->toContain('event: turn.completed');
    });

    it('returns 404 for non-existent turn', function () {
        $fixture = createResumeFixture();
        $this->actingAs($fixture['user']);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => '01NONEXISTENT000000000000000',
        ]));

        $response->assertNotFound();
    });

    it('returns 403 for turn belonging to another user', function () {
        $fixture = createResumeFixture();

        // Create turn owned by a different user
        $otherUser = User::factory()->create([
            'company_id' => Company::LICENSEE_ID,
            'employee_id' => $fixture['employee']->id,
        ]);

        $turn = createTurnWithEvents($otherUser->id);

        // Act as the fixture user (not the owner)
        $this->actingAs($fixture['user']);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
        ]));

        $response->assertForbidden();
    });

    it('includes seq and turn_id in every event payload', function () {
        $fixture = createResumeFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithEvents($fixture['user']->id);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
        ]));

        $content = $response->streamedContent();
        $dataLines = array_filter(
            explode("\n", $content),
            fn ($line) => str_starts_with($line, 'data: '),
        );

        $turnEventCount = 0;

        foreach ($dataLines as $line) {
            $data = json_decode(substr($line, 6), true);

            if ($data === null) {
                continue;
            }

            // Meta events have 'type' key, turn events have 'turn_id'
            if (isset($data['turn_id'])) {
                $turnEventCount++;
                expect($data['turn_id'])->toBe($turn->id);
                expect($data)->toHaveKey('seq');
                expect($data)->toHaveKey('event_type');
                expect($data)->toHaveKey('occurred_at');
            }
        }

        // We created 12 events in createTurnWithEvents (including the terminal)
        expect($turnEventCount)->toBeGreaterThanOrEqual(10);
    });

    it('emits stream_end meta event for terminal turns', function () {
        $fixture = createResumeFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithEvents($fixture['user']->id);

        $response = $this->get(route('ai.chat.turn.events', [
            'turnId' => $turn->id,
        ]));

        $content = $response->streamedContent();

        // Should end with a meta event
        expect($content)->toContain('"type":"stream_end"');
        expect($content)->toContain('"reason":"turn_terminal"');
        expect($content)->toContain('"status":"completed"');
    });
});
