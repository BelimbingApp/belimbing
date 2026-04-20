<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\AgenticFinalResponseStreamer;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\RuntimeResponseFactory;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('prepends client actions when the final stream ends without content deltas', function (): void {
    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn((function (): Generator {
            yield [
                'type' => 'done',
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 0],
                'latency_ms' => 42,
            ];
        })());

    $runRecorder = Mockery::mock(RunRecorder::class);
    $runRecorder->shouldReceive('complete')
        ->once()
        ->with('run_123', Mockery::on(function (array $meta): bool {
            return $meta['latency_ms'] === 42
                && $meta['tokens']['prompt'] === 11
                && $meta['tokens']['completion'] === 0;
        }));

    $streamer = new AgenticFinalResponseStreamer(
        $llmClient,
        $runRecorder,
        app(RuntimeResponseFactory::class),
        Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
    );

    $events = iterator_to_array($streamer->streamFinalResponse(
        'run_123',
        [
            'api_type' => null,
            'model' => 'gpt-4.1',
            'execution_controls' => ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.3),
            'timeout' => 60,
            'provider_name' => 'test-provider',
        ],
        [
            'api_key' => 'test-key',
            'base_url' => 'https://api.example.test',
        ],
        [
            'api_messages' => [
                ['role' => 'user', 'content' => 'Open the dashboard'],
            ],
            'tools' => [],
            'tool_actions' => [],
            'client_actions' => ['<agent-action>Livewire.navigate(\'/dashboard\')</agent-action>'],
            'retry_attempts' => [],
            'fallback_attempts' => [],
            'hooks' => [],
        ],
    ), false);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('done')
        ->and($events[0]['data']['content'])->toBe("<agent-action>Livewire.navigate('/dashboard')</agent-action>\n")
        ->and($events[0]['data']['meta']['latency_ms'])->toBe(42)
        ->and($events[0]['data']['meta']['tokens']['prompt'])->toBe(11)
        ->and($events[0]['data']['meta']['tokens']['completion'])->toBe(0);
});
