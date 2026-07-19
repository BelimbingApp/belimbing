<?php

use App\Modules\Core\AI\DTO\ToolFinishedPayload;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Jobs\RunChatTurnJob;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ChatTurnRunner;
use App\Modules\Core\AI\Services\RunEventPublisher;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Auth;

const REPLAY_TEST_SESSION = 'sess_replay_test';
const REPLAY_TEST_RUN = '01ARZ3NDEKTSV4RRFFQ69G5FAZ';

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

function createTurnWithReplayEvents(int $userId): AiRun
{
    $turn = AiRun::query()->create([
        'id' => REPLAY_TEST_RUN,
        'employee_id' => Employee::LARA_ID,
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'session_id' => REPLAY_TEST_SESSION,
        'acting_for_user_id' => $userId,
        'status' => AiRunStatus::Queued,
        'current_phase' => RunPhase::WaitingForWorker,
    ]);

    $pub = app(RunEventPublisher::class);

    $pub->turnStarted($turn);
    $turn->transitionTo(AiRunStatus::Running);
    $pub->phaseChanged($turn, RunPhase::AwaitingLlm, RunPhase::AwaitingLlm->label());
    $pub->thinkingStarted($turn);
    $pub->phaseChanged($turn, RunPhase::RunningTool, 'bash');
    $pub->toolStarted($turn, 'bash', '{"cmd":"ls"}', 0);
    $pub->toolFinished($turn, 'bash', new ToolFinishedPayload(
        status: 'success',
        resultPreview: '10 files',
        durationMs: 150,
        resultLength: 32,
    ));
    $pub->phaseChanged($turn, RunPhase::StreamingAnswer, 'Responding…');
    $pub->outputDelta($turn, 'Hello world');
    $pub->outputBlockCommitted($turn, 'markdown', 'Hello world');
    $pub->turnCompleted($turn, ['elapsed_ms' => 1200]);

    return $turn->refresh();
}

describe('RunEventStreamController', function () {
    it('returns all events for a completed turn as JSON', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'runId' => $turn->id,
            'after_seq' => 0,
        ]));

        $response->assertOk();
        $json = $response->json();

        expect($json)->toHaveKey('events');
        expect($json)->toHaveKey('run_id', $turn->id);
        expect($json)->toHaveKey('status', 'succeeded');

        $eventTypes = collect($json['events'])->pluck('event_type')->all();

        expect($eventTypes)
            ->toContain('run.started')
            ->toContain('run.started')
            ->toContain('run.phase_changed')
            ->toContain('assistant.thinking_started')
            ->toContain('tool.started')
            ->toContain('tool.finished')
            ->toContain('assistant.output_delta')
            ->toContain('assistant.output_block_committed')
            ->toContain('run.completed');
    });

    it('resumes from after_seq skipping earlier events', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'runId' => $turn->id,
            'after_seq' => 4,
        ]));

        $response->assertOk();
        $json = $response->json();

        $events = $json['events'];
        $eventTypes = collect($events)->pluck('event_type')->all();
        $seqs = collect($events)->pluck('seq')->all();

        expect($eventTypes)
            ->not->toContain('run.started')
            ->toContain('tool.started')
            ->toContain('tool.finished')
            ->toContain('run.completed');

        expect($seqs)->not->toBeEmpty()
            ->and($seqs[0])->toBe(5);

        foreach ($events as $event) {
            expect($event['seq'])->toBeGreaterThan(4)
                ->and($event['run_id'])->toBe($turn->id);
        }
    });

    it('returns 404 for non-existent turn', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $response = $this->get(route('ai.chat.turn.events', [
            'runId' => '01NONEXISTENT000000000000000',
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
            'runId' => $turn->id,
        ]));

        $response->assertForbidden();
    });

    it('includes seq and run_id in every event', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->getJson(route('ai.chat.turn.events', [
            'runId' => $turn->id,
        ]));

        $json = $response->json();
        $events = $json['events'];

        expect(count($events))->toBeGreaterThanOrEqual(10);

        foreach ($events as $event) {
            expect($event['run_id'])->toBe($turn->id);
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
            'runId' => $turn->id,
        ]));

        $json = $response->json();

        expect($json)->toHaveKey('run_id', $turn->id);
        expect($json)->toHaveKey('status', 'succeeded');
        expect($json)->toHaveKey('started_at');
        expect($json)->toHaveKey('created_at');
        expect($json)->toHaveKey('cancel_requested_at', null);
    });
});

describe('RunEventStreamController streaming', function () {
    it('exposes pending cancellation timestamp while a turn is still active', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'session_id' => REPLAY_TEST_SESSION,
            'acting_for_user_id' => $fixture['user']->id,
            'status' => AiRunStatus::Running,
            'current_phase' => RunPhase::AwaitingLlm,
        ]);
        $turn->requestCancel('User pressed stop');

        $response = $this->getJson(route('ai.chat.turn.events', [
            'runId' => $turn->id,
        ]));

        $response->assertOk();

        expect($response->json('status'))->toBe('running')
            ->and($response->json('cancel_requested_at'))->toBe($turn->refresh()->cancel_requested_at?->toIso8601String());
    });

    it('streams persisted events without marking client observation as cancellation', function () {
        $fixture = createReplayFixture();
        $this->actingAs($fixture['user']);

        $turn = createTurnWithReplayEvents($fixture['user']->id);

        $response = $this->get(route('ai.chat.turn.stream', [
            'runId' => $turn->id,
        ]));

        $response->assertOk();
        $content = $response->streamedContent();

        expect($content)->toContain('"event_type":"run.started"')
            ->and($content)->toContain('"event_type":"run.completed"')
            ->and($content)->toContain('"_stream_complete":true');

        expect($turn->refresh()->cancel_requested_at)->toBeNull();
    });

    it('executes a queued chat turn only after the queue worker claims it', function () {
        $fixture = createReplayFixture();
        Auth::login($fixture['user']);

        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'source' => 'chat',
            'execution_mode' => 'interactive',
            'session_id' => REPLAY_TEST_SESSION,
            'acting_for_user_id' => $fixture['user']->id,
            'status' => AiRunStatus::Queued,
            'current_phase' => RunPhase::WaitingForWorker,
        ]);

        $runner = Mockery::mock(ChatTurnRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn (AiRun $claimedTurn): bool => $claimedTurn->id === $turn->id
                && data_get($claimedTurn->runtime_meta, 'execution_owner') === 'queue_worker'))
            ->andReturnUsing(function (AiRun $claimedTurn): void {
                $claimedTurn->forceFill([
                    'status' => AiRunStatus::Succeeded,
                    'current_phase' => RunPhase::Finalizing,
                    'finished_at' => now(),
                ])->save();
            });

        (new RunChatTurnJob($turn->id))->handle($runner);

        expect(data_get($turn->refresh()->runtime_meta, 'execution_owner'))->toBe('queue_worker');
    });

    it('does not execute a queued job already claimed by another fresh owner', function () {
        $fixture = createReplayFixture();
        Auth::login($fixture['user']);

        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'source' => 'chat',
            'execution_mode' => 'interactive',
            'session_id' => REPLAY_TEST_SESSION,
            'acting_for_user_id' => $fixture['user']->id,
            'status' => AiRunStatus::Queued,
            'current_phase' => RunPhase::WaitingForWorker,
            'runtime_meta' => [
                'execution_owner' => 'queue_worker',
                'execution_owner_claimed_at' => now()->toIso8601String(),
            ],
        ]);

        $runner = Mockery::mock(ChatTurnRunner::class);
        $runner->shouldNotReceive('run');

        (new RunChatTurnJob($turn->id))->handle($runner);

        expect($turn->refresh()->status)->toBe(AiRunStatus::Queued);
    });

    it('does not execute an already running chat turn from a duplicate queue job', function () {
        $fixture = createReplayFixture();
        Auth::login($fixture['user']);

        $turn = AiRun::query()->create([
            'employee_id' => Employee::LARA_ID,
            'source' => 'chat',
            'execution_mode' => 'interactive',
            'session_id' => REPLAY_TEST_SESSION,
            'acting_for_user_id' => $fixture['user']->id,
            'status' => AiRunStatus::Running,
            'current_phase' => RunPhase::AwaitingLlm,
            'started_at' => now(),
        ]);

        $runner = Mockery::mock(ChatTurnRunner::class);
        $runner->shouldNotReceive('run');

        (new RunChatTurnJob($turn->id))->handle($runner);

        expect($turn->refresh()->status)->toBe(AiRunStatus::Running)
            ->and(data_get($turn->runtime_meta, 'execution_owner'))->toBeNull();
    });
});
