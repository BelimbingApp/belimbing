<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Employee\Models\Employee;
use Mockery\MockInterface;

const MAT_TEST_SESSION = 'sess_materializer_test';
const MAT_TEST_RUN_ID = 'run_mat_test_001';

/**
 * Create a ChatTurn and advance it to a given state with events.
 */
function createMaterializerTurn(): ChatTurn
{
    return ChatTurn::query()->create([
        'employee_id' => Employee::query()->first()->id,
        'session_id' => MAT_TEST_SESSION,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
    ]);
}

/**
 * Populate a turn with a standard happy-path event sequence.
 *
 * Mirrors the bridge flow: turnStarted (→Booting) → runStarted + transitionTo(Running)
 * → tool events → done → turnCompleted (→Completed).
 */
function populateHappyPathEvents(ChatTurn $turn, TurnEventPublisher $pub): void
{
    $pub->turnStarted($turn);
    $pub->runStarted($turn, MAT_TEST_RUN_ID);
    $turn->transitionTo(TurnStatus::Running);
    $pub->phaseChanged($turn, TurnPhase::Thinking, 'Thinking…');
    $pub->thinkingStarted($turn);
    $pub->phaseChanged($turn, TurnPhase::RunningTool, 'bash');
    $pub->toolStarted($turn, 'bash', '{"cmd":"ls"}', 0);
    $pub->toolFinished($turn, 'bash', 'success', '10 files', 150, 32);
    $pub->phaseChanged($turn, TurnPhase::Thinking, 'Thinking…');
    $pub->heartbeat($turn, 500);
    $pub->phaseChanged($turn, TurnPhase::StreamingAnswer, 'Responding…');
    $pub->outputDelta($turn, 'Hello ');
    $pub->outputDelta($turn, 'world');
    $pub->phaseChanged($turn, TurnPhase::Finalizing, 'Finishing up…');
    $pub->usageUpdated($turn, ['prompt_tokens' => 100, 'completion_tokens' => 50]);
    $pub->outputBlockCommitted($turn, 'markdown', 'Hello world');
    $pub->turnCompleted($turn, ['run_id' => MAT_TEST_RUN_ID, 'elapsed_ms' => 1200]);
}

/**
 * Create a mock MessageManager that allows all append calls.
 *
 * MessageManager::appendAssistantMessage returns a final Message DTO.
 * Mockery cannot auto-generate a return for final classes, so we return
 * a real Message instance from the mock.
 */
function mockMessageManager(): MockInterface
{
    $mm = Mockery::mock(MessageManager::class);

    // Default: allow all append methods, return null for void methods
    $mm->shouldReceive('appendThinking')->byDefault();
    $mm->shouldReceive('appendToolCall')->byDefault();
    $mm->shouldReceive('appendToolResult')->byDefault();
    $mm->shouldReceive('appendHookAction')->byDefault();
    $mm->shouldReceive('appendAssistantMessage')->byDefault()->andReturn(
        new Message(
            role: 'assistant',
            content: '',
            timestamp: new DateTimeImmutable,
        ),
    );

    return $mm;
}

// ------------------------------------------------------------------
// ChatRunPersister::materializeFromTurn — happy path
// ------------------------------------------------------------------

describe('ChatRunPersister materializeFromTurn', function () {
    it('materializes a complete conversation transcript from turn events', function () {
        $turn = createMaterializerTurn();
        $pub = app(TurnEventPublisher::class);
        populateHappyPathEvents($turn, $pub);

        $mm = mockMessageManager();

        $mm->shouldReceive('appendThinking')
            ->once()
            ->with(
                $turn->employee_id,
                MAT_TEST_SESSION,
                MAT_TEST_RUN_ID,
            );

        $mm->shouldReceive('appendToolCall')
            ->once()
            ->with(
                $turn->employee_id,
                MAT_TEST_SESSION,
                MAT_TEST_RUN_ID,
                'bash',
                '{"cmd":"ls"}',
                0,
            );

        $mm->shouldReceive('appendToolResult')
            ->once()
            ->withArgs(function ($empId, $sessId, $runId, $entry) use ($turn) {
                return $empId === $turn->employee_id
                    && $sessId === MAT_TEST_SESSION
                    && $runId === MAT_TEST_RUN_ID
                    && $entry->toolName === 'bash'
                    && $entry->status === 'success'
                    && $entry->resultPreview === '10 files'
                    && $entry->durationMs === 150
                    && $entry->resultLength === 32;
            });

        $mm->shouldReceive('appendAssistantMessage')
            ->once()
            ->with(
                $turn->employee_id,
                MAT_TEST_SESSION,
                'Hello world',
                MAT_TEST_RUN_ID,
                Mockery::on(fn ($meta) => ($meta['tokens']['prompt_tokens'] ?? null) === 100
                    && ($meta['tokens']['completion_tokens'] ?? null) === 50),
            )
            ->andReturn(new Message(
                role: 'assistant',
                content: 'Hello world',
                timestamp: new DateTimeImmutable,
            ));

        $persister = new ChatRunPersister;
        $persister->materializeFromTurn($turn, $mm, $turn->employee_id, MAT_TEST_SESSION);
    });

    it('includes extra meta in the assistant message', function () {
        $turn = createMaterializerTurn();
        $pub = app(TurnEventPublisher::class);
        populateHappyPathEvents($turn, $pub);

        $mm = mockMessageManager();

        $mm->shouldReceive('appendAssistantMessage')
            ->once()
            ->with(
                $turn->employee_id,
                MAT_TEST_SESSION,
                'Hello world',
                MAT_TEST_RUN_ID,
                Mockery::on(fn ($meta) => ($meta['prompt_package'] ?? null) === 'test_package'),
            )
            ->andReturn(new Message(
                role: 'assistant',
                content: 'Hello world',
                timestamp: new DateTimeImmutable,
            ));

        $persister = new ChatRunPersister;
        $persister->materializeFromTurn(
            $turn,
            $mm,
            $turn->employee_id,
            MAT_TEST_SESSION,
            ['prompt_package' => 'test_package'],
        );
    });

    it('materializes error transcript for failed turns', function () {
        $turn = createMaterializerTurn();
        $pub = app(TurnEventPublisher::class);

        $pub->turnStarted($turn);
        $pub->runStarted($turn, MAT_TEST_RUN_ID);
        $turn->transitionTo(TurnStatus::Running);
        $pub->turnFailed($turn, 'rate_limit', 'API rate limited');

        $mm = mockMessageManager();

        $mm->shouldReceive('appendAssistantMessage')
            ->once()
            ->withArgs(function ($empId, $sessId, $content, $runId, $meta) use ($turn) {
                return $empId === $turn->employee_id
                    && $sessId === MAT_TEST_SESSION
                    && str_contains($content, 'API rate limited')
                    && $runId === MAT_TEST_RUN_ID;
            })
            ->andReturn(new Message(
                role: 'assistant',
                content: 'error',
                timestamp: new DateTimeImmutable,
            ));

        $persister = new ChatRunPersister;
        $persister->materializeFromTurn($turn, $mm, $turn->employee_id, MAT_TEST_SESSION);
    });

    it('skips assistant message when turn has no content and no error', function () {
        $turn = createMaterializerTurn();
        $pub = app(TurnEventPublisher::class);

        $pub->turnStarted($turn);
        $pub->runStarted($turn, MAT_TEST_RUN_ID);
        $turn->transitionTo(TurnStatus::Running);
        $pub->phaseChanged($turn, TurnPhase::Thinking, 'Thinking…');
        $pub->thinkingStarted($turn);
        $pub->turnCompleted($turn);

        $mm = mockMessageManager();
        $mm->shouldReceive('appendThinking')->once();
        $mm->shouldNotReceive('appendAssistantMessage');

        $persister = new ChatRunPersister;
        $persister->materializeFromTurn($turn, $mm, $turn->employee_id, MAT_TEST_SESSION);
    });

    it('materializes tool denied as hook action', function () {
        $turn = createMaterializerTurn();
        $pub = app(TurnEventPublisher::class);

        $pub->turnStarted($turn);
        $pub->runStarted($turn, MAT_TEST_RUN_ID);
        $turn->transitionTo(TurnStatus::Running);
        $pub->toolDenied($turn, 'dangerous_tool', 'blocked by policy', 'hook');
        $pub->outputBlockCommitted($turn, 'markdown', 'Tool was denied');
        $pub->turnCompleted($turn);

        $mm = mockMessageManager();

        $mm->shouldReceive('appendHookAction')
            ->once()
            ->with(
                $turn->employee_id,
                MAT_TEST_SESSION,
                MAT_TEST_RUN_ID,
                'pre_tool_use',
                'tool_denied',
                Mockery::on(fn ($d) => $d['tool'] === 'dangerous_tool'
                    && $d['reason'] === 'blocked by policy'),
            );

        $mm->shouldReceive('appendAssistantMessage')
            ->once()
            ->with(
                $turn->employee_id,
                MAT_TEST_SESSION,
                'Tool was denied',
                MAT_TEST_RUN_ID,
                Mockery::any(),
            )
            ->andReturn(new Message(
                role: 'assistant',
                content: 'Tool was denied',
                timestamp: new DateTimeImmutable,
            ));

        $persister = new ChatRunPersister;
        $persister->materializeFromTurn($turn, $mm, $turn->employee_id, MAT_TEST_SESSION);
    });

    it('materializes tool error payload in tool result entry', function () {
        $turn = createMaterializerTurn();
        $pub = app(TurnEventPublisher::class);

        $pub->turnStarted($turn);
        $pub->runStarted($turn, MAT_TEST_RUN_ID);
        $turn->transitionTo(TurnStatus::Running);
        $pub->toolStarted($turn, 'bash', '{"cmd":"rm -rf /"}', 0);
        $pub->toolFinished($turn, 'bash', 'error', 'Permission denied', 80, 17, [
            'code' => 'permission_denied',
            'message' => 'Not allowed',
        ]);
        $pub->outputBlockCommitted($turn, 'markdown', 'Error occurred');
        $pub->turnCompleted($turn);

        $mm = mockMessageManager();

        $mm->shouldReceive('appendToolCall')->once();
        $mm->shouldReceive('appendToolResult')
            ->once()
            ->withArgs(function ($empId, $sessId, $runId, $entry) {
                return $entry->status === 'error'
                    && $entry->resultLength === 17
                    && $entry->errorPayload['code'] === 'permission_denied';
            });
        $mm->shouldReceive('appendAssistantMessage')
            ->once()
            ->andReturn(new Message(
                role: 'assistant',
                content: 'Error occurred',
                timestamp: new DateTimeImmutable,
            ));

        $persister = new ChatRunPersister;
        $persister->materializeFromTurn($turn, $mm, $turn->employee_id, MAT_TEST_SESSION);
    });
});
