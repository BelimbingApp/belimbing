<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunCall;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Values\CallUsage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const RR_RUN_ID = 'run_recorder_test_001';

function rrSeedRun(string $runId = RR_RUN_ID): AiRun
{
    return AiRun::unguarded(fn () => AiRun::query()->create([
        'id' => $runId,
        'employee_id' => 1,
        'session_id' => 'sess_test',
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Running,
        'started_at' => now(),
    ]));
}

it('inserts an ai_run_calls row and refreshes run-level aggregates', function (): void {
    rrSeedRun();
    $recorder = new RunRecorder;

    $call = $recorder->recordCall(
        runId: RR_RUN_ID,
        attemptIndex: 0,
        provider: 'moonshot',
        model: 'kimi-k2.6',
        finishReason: 'tool_calls',
        latencyMs: 1234,
        usage: CallUsage::fromProviderArray([
            'prompt_tokens' => 1754,
            'completion_tokens' => 128,
            'total_tokens' => 1882,
            'prompt_tokens_details' => ['cached_tokens' => 1024],
        ]),
    );

    expect($call)->not->toBeNull()
        ->and($call->prompt_tokens)->toBe(1754)
        ->and($call->cached_input_tokens)->toBe(1024)
        ->and($call->completion_tokens)->toBe(128)
        ->and($call->total_tokens)->toBe(1882)
        ->and($call->finish_reason)->toBe('tool_calls')
        ->and($call->latency_ms)->toBe(1234);

    $run = AiRun::query()->find(RR_RUN_ID);
    expect($run->call_count)->toBe(1)
        ->and($run->prompt_tokens)->toBe(1754)
        ->and($run->cached_input_tokens)->toBe(1024)
        ->and($run->completion_tokens)->toBe(128)
        ->and($run->total_tokens)->toBe(1882);
});

it('sums tokens across multiple per-call rows from a tool-call loop', function (): void {
    rrSeedRun();
    $recorder = new RunRecorder;

    foreach ([
        ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050],
        ['prompt_tokens' => 1200, 'completion_tokens' => 80, 'total_tokens' => 1280],
        ['prompt_tokens' => 1500, 'completion_tokens' => 200, 'total_tokens' => 1700],
    ] as $i => $usagePayload) {
        $recorder->recordCall(
            runId: RR_RUN_ID,
            attemptIndex: $i,
            provider: 'openai',
            model: 'gpt-5.4',
            finishReason: $i < 2 ? 'tool_calls' : 'stop',
            latencyMs: 500,
            usage: CallUsage::fromProviderArray($usagePayload),
        );
    }

    $run = AiRun::query()->find(RR_RUN_ID);
    expect($run->call_count)->toBe(3)
        ->and($run->prompt_tokens)->toBe(3700)
        ->and($run->completion_tokens)->toBe(330)
        ->and($run->total_tokens)->toBe(4030);

    expect(AiRunCall::query()->where('run_id', RR_RUN_ID)->count())->toBe(3);
});

it('upserts on duplicate (run_id, attempt_index) so a re-emitted done event is idempotent', function (): void {
    rrSeedRun();
    $recorder = new RunRecorder;

    $usage = CallUsage::fromProviderArray(['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]);

    $recorder->recordCall(RR_RUN_ID, 0, 'openai', 'gpt-5.4', 'stop', 500, $usage);
    $recorder->recordCall(RR_RUN_ID, 0, 'openai', 'gpt-5.4', 'stop', 500, $usage);

    expect(AiRunCall::query()->where('run_id', RR_RUN_ID)->count())->toBe(1);

    $run = AiRun::query()->find(RR_RUN_ID);
    expect($run->call_count)->toBe(1)
        ->and($run->prompt_tokens)->toBe(100);
});

it('returns null and is a no-op when the run row does not exist', function (): void {
    $recorder = new RunRecorder;

    $result = $recorder->recordCall(
        runId: 'nonexistent_run',
        attemptIndex: 0,
        provider: 'openai',
        model: 'gpt-5.4',
        finishReason: 'stop',
        latencyMs: 100,
        usage: CallUsage::fromProviderArray(['prompt_tokens' => 1, 'completion_tokens' => 1]),
    );

    expect($result)->toBeNull()
        ->and(AiRunCall::query()->count())->toBe(0);
});

it('preserves call-aggregated tokens when complete() is called on a multi-call run', function (): void {
    rrSeedRun();
    $recorder = new RunRecorder;

    $recorder->recordCall(RR_RUN_ID, 0, 'openai', 'gpt-5.4', 'tool_calls', 400,
        CallUsage::fromProviderArray(['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050]));
    $recorder->recordCall(RR_RUN_ID, 1, 'openai', 'gpt-5.4', 'stop', 500,
        CallUsage::fromProviderArray(['prompt_tokens' => 1500, 'completion_tokens' => 200, 'total_tokens' => 1700]));

    // Simulate the legacy complete() metadata that only carries the LAST iteration's tokens.
    $recorder->complete(RR_RUN_ID, [
        'provider_name' => 'openai',
        'model' => 'gpt-5.4',
        'latency_ms' => 1200,
        'tokens' => ['prompt' => 1500, 'completion' => 200],
    ]);

    $run = AiRun::query()->find(RR_RUN_ID);

    expect($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->prompt_tokens)->toBe(2500)
        ->and($run->completion_tokens)->toBe(250)
        ->and($run->call_count)->toBe(2);
});
