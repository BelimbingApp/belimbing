<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Livewire\ControlPlane;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

const CONTROL_PLANE_OVERSIZED_RUN_ID = 'run_control_plane_oversized';
const CONTROL_PLANE_WINDOWED_RUN_ID = 'run_control_plane_windowed';
const CONTROL_PLANE_LICENSEE_NAME = 'Test Licensee';
const CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH = 'app/ai/wire-logs';
const CONTROL_PLANE_WIRE_LOG_EXTENSION = '.jsonl';
const CONTROL_PLANE_PROVIDER_NAME = 'test-provider';
const CONTROL_PLANE_MODEL = 'test-model';
const CONTROL_PLANE_EXECUTION_MODE = 'streaming';

beforeEach(function (): void {
    $this->originalStoragePath = app()->storagePath();
    $this->testingStoragePath = base_path('storage/framework/testing/control-plane-storage-'.bin2hex(random_bytes(4)));

    File::ensureDirectoryExists($this->testingStoragePath);
    app()->useStoragePath($this->testingStoragePath);
});

afterEach(function (): void {
    File::delete(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_OVERSIZED_RUN_ID.CONTROL_PLANE_WIRE_LOG_EXTENSION));
    File::delete(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_WINDOWED_RUN_ID.CONTROL_PLANE_WIRE_LOG_EXTENSION));

    if (isset($this->originalStoragePath) && is_string($this->originalStoragePath)) {
        app()->useStoragePath($this->originalStoragePath);
    }

    if (isset($this->testingStoragePath) && is_string($this->testingStoragePath)) {
        File::deleteDirectory($this->testingStoragePath);
    }
});

function createControlPlaneRun(string $runId): void
{
    AiRun::unguarded(fn () => AiRun::query()->create([
        'id' => $runId,
        'employee_id' => Employee::LARA_ID,
        'session_id' => 'sess_'.$runId,
        'source' => 'chat',
        'execution_mode' => CONTROL_PLANE_EXECUTION_MODE,
        'status' => AiRunStatus::Succeeded,
        'provider_name' => CONTROL_PLANE_PROVIDER_NAME,
        'model' => CONTROL_PLANE_MODEL,
        'started_at' => now(),
        'finished_at' => now(),
    ]));
}

it('renders a bounded wire-log preview for oversized run logs', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    Company::provisionLicensee(CONTROL_PLANE_LICENSEE_NAME);
    Employee::provisionLara();

    $user = createAdminUser();

    createControlPlaneRun(CONTROL_PLANE_OVERSIZED_RUN_ID);

    $lines = [];

    for ($index = 0; $index < 45; $index++) {
        $lines[] = json_encode([
            'at' => now()->copy()->addSeconds($index)->toIso8601String(),
            'type' => 'llm.response_body',
            'raw_body' => $index === 0
                ? str_repeat('X', (64 * 1024) + 512)
                : json_encode(['index' => $index, 'ok' => true]),
            'decoded_body' => $index === 0 ? null : ['index' => $index],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    File::ensureDirectoryExists(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH));
    File::put(
        storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_OVERSIZED_RUN_ID.CONTROL_PLANE_WIRE_LOG_EXTENSION),
        implode("\n", $lines)."\n",
    );

    $response = $this->actingAs($user)->get(route('admin.ai.control-plane', [
        'tab' => 'inspector',
        'runId' => CONTROL_PLANE_OVERSIZED_RUN_ID,
    ]));

    $response->assertOk()
        ->assertSee('Wire Log')
        ->assertSee('Showing entries 1-45 of 45 retained wire-log entries.')
        ->assertSee('This run retained')
        ->assertSee('Large entries can be opened raw in a separate tab without loading them into the inspector response.')
        ->assertSee('llm.response_body')
        ->assertSee('#1')
        ->assertSee('{"raw_body":"')
        ->assertSee('Payload preview omitted because this wire-log entry exceeds 64 KB.')
        ->assertSee('Open Raw')
        ->assertSee(route('admin.ai.runs.wire-log-entry', [
            'runId' => CONTROL_PLANE_OVERSIZED_RUN_ID,
            'entryNumber' => 1,
        ]));
});

it('navigates wire-log windows for large runs', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    Company::provisionLicensee(CONTROL_PLANE_LICENSEE_NAME);
    Employee::provisionLara();

    $user = createAdminUser();

    createControlPlaneRun(CONTROL_PLANE_WINDOWED_RUN_ID);

    $lines = [];

    $lines[] = json_encode([
        'at' => now()->copy()->addSecond()->toIso8601String(),
        'type' => 'llm.stream_line',
        'raw_line' => 'data: {"choices":[{"delta":{"reasoning_content":" the"},"finish_reason":null}]}',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $lines[] = json_encode([
        'at' => now()->copy()->addSeconds(2)->toIso8601String(),
        'type' => 'llm.stream_line',
        'raw_line' => 'data: {"choices":[{"delta":{},"finish_reason":"tool_calls"}]}',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $lines[] = json_encode([
        'at' => now()->copy()->addSeconds(3)->toIso8601String(),
        'type' => 'llm.stream_line',
        'raw_line' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    for ($index = 4; $index <= 245; $index++) {
        $lines[] = json_encode([
            'at' => now()->copy()->addSeconds($index)->toIso8601String(),
            'type' => 'llm.stream_line',
            'raw_line' => 'entry-'.$index,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    File::ensureDirectoryExists(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH));
    File::put(
        storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_WINDOWED_RUN_ID.CONTROL_PLANE_WIRE_LOG_EXTENSION),
        implode("\n", $lines)."\n",
    );

    Livewire::actingAs($user)
        ->test(ControlPlane::class)
        ->set('inspectRunId', CONTROL_PLANE_WINDOWED_RUN_ID)
        ->call('inspectRun')
        ->assertSet('wireLogLimit', 100)
        ->assertSee('Showing entries 1-100 of 245 retained wire-log entries.')
        ->assertSee('Computed from this window only')
        ->assertSee('reasoning_content: " the"')
        ->assertSee('finish_reason: tool_calls')
        ->assertSee('[]')
        ->assertSee('entry-4')
        ->assertSee('entry-100')
        ->set('wireLogLimit', 250)
        ->assertDispatched('wire-log-window-changed')
        ->assertSee('Showing entries 1-245 of 245 retained wire-log entries.')
        ->set('wireLogLimit', 100)
        ->assertDispatched('wire-log-window-changed')
        ->call('lastWireLogEntries', 145)
        ->assertDispatched('wire-log-window-changed')
        ->assertSee('Showing entries 146-245 of 245 retained wire-log entries.')
        ->assertSee('entry-245')
        ->set('wireLogStartEntry', '101')
        ->call('jumpToWireLogEntry', 245)
        ->assertDispatched('wire-log-window-changed')
        ->assertSee('Showing entries 101-200 of 245 retained wire-log entries.')
        ->assertSee('entry-101')
        ->assertSee('entry-200');
});

it('tracks manually selected control plane tabs in Livewire state', function (): void {
    Company::provisionLicensee(CONTROL_PLANE_LICENSEE_NAME);
    Employee::provisionLara();

    $user = createAdminUser();

    Livewire::actingAs($user)
        ->test(ControlPlane::class)
        ->assertSet('activeTab', 'inspector')
        ->call('setActiveTab', 'health')
        ->assertSet('activeTab', 'health')
        ->call('setActiveTab', 'unknown')
        ->assertSet('activeTab', 'inspector');
});

const CONTROL_PLANE_READABLE_RUN_ID = 'run_control_plane_readable';

it('renders the readable view with reassembled artifacts, anomaly chips, attempt summaries, and copy-as-cURL', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    Company::provisionLicensee(CONTROL_PLANE_LICENSEE_NAME);
    Employee::provisionLara();

    $user = createAdminUser();

    createControlPlaneRun(CONTROL_PLANE_READABLE_RUN_ID);

    $writeAt = fn (int $seconds, string $type, array $payload): string => json_encode(
        array_merge(['at' => now()->copy()->addSeconds($seconds)->toIso8601String(), 'type' => $type], $payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );

    $sse = fn (array $delta, ?string $finishReason = null): string => 'data: '.json_encode([
        'choices' => [[
            'index' => 0,
            'delta' => $delta,
            'finish_reason' => $finishReason,
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $lines = [
        $writeAt(0, 'llm.request', [
            'endpoint' => '/v1/chat/completions',
            'stream' => true,
            'request' => [
                'base_url' => 'https://api.openai.com',
                'api_key' => '[redacted]',
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => [],
                'provider_name' => 'openai',
                'execution_controls' => [],
                'timeout' => 30,
                'api_type' => 'chat',
            ],
            'mapped' => [
                'payload' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => true],
                'headers' => ['Authorization' => '[redacted]', 'Content-Type' => 'application/json'],
                'meta' => [],
            ],
        ]),
        $writeAt(1, 'llm.response_status', ['status_code' => 503, 'stream' => true]),
        $writeAt(1, 'llm.error', ['stage' => 'response', 'message' => 'rate limited', 'context' => []]),
        $writeAt(2, 'llm.request', [
            'endpoint' => '/v1/messages',
            'stream' => true,
            'request' => [
                'base_url' => 'https://api.anthropic.com',
                'api_key' => '[redacted]',
                'model' => 'claude-opus-4',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => [],
                'provider_name' => 'anthropic',
                'execution_controls' => [],
                'timeout' => 30,
                'api_type' => 'chat',
            ],
            'mapped' => [
                'payload' => ['model' => 'claude-opus-4'],
                'headers' => ['Authorization' => '[redacted]'],
                'meta' => [],
            ],
        ]),
        $writeAt(3, 'llm.response_status', ['status_code' => 200, 'stream' => true]),
        $writeAt(4, 'llm.stream_line', ['raw_line' => $sse(['content' => 'Hello, '])]),
        $writeAt(5, 'llm.stream_line', ['raw_line' => $sse(['content' => 'world!'])]),
        $writeAt(6, 'llm.stream_line', ['raw_line' => $sse(['tool_calls' => [['index' => 0, 'function' => ['name' => 'lookup_user', 'arguments' => '{"id":42}']]]])]),
        $writeAt(7, 'llm.stream_line', ['raw_line' => $sse([], 'tool_calls')]),
        $writeAt(7, 'llm.complete', ['context' => []]),
    ];

    File::ensureDirectoryExists(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH));
    File::put(
        storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_READABLE_RUN_ID.'.jsonl'),
        implode("\n", $lines)."\n",
    );

    $response = $this->actingAs($user)->get(route('admin.ai.control-plane', [
        'tab' => 'inspector',
        'runId' => CONTROL_PLANE_READABLE_RUN_ID,
    ]));

    $response->assertOk()
        ->assertSee('Anomaly signals')
        ->assertSee('HTTP 503')
        ->assertSee('Attempt 1')
        ->assertSee('openai')
        ->assertSee('claude-opus-4')
        ->assertSee('Copy as cURL')
        ->assertSee('Bearer $API_KEY', escape: false)
        ->assertSee('Reassembled content')
        ->assertSee('Hello, world!')
        ->assertSee('lookup_user')
        ->assertSee('valid JSON')
        ->assertSee('id="wire-log-entry-1"', escape: false)
        ->assertSee('id="wire-log-entry-6"', escape: false);

    File::delete(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_READABLE_RUN_ID.'.jsonl'));
});

it('streams oversized raw wire-log entries without loading them through Livewire', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    Company::provisionLicensee(CONTROL_PLANE_LICENSEE_NAME);
    Employee::provisionLara();

    $user = createAdminUser();

    createControlPlaneRun(CONTROL_PLANE_OVERSIZED_RUN_ID);

    $rawBody = str_repeat('X', (64 * 1024) + 512);

    File::ensureDirectoryExists(storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH));
    File::put(
        storage_path(CONTROL_PLANE_WIRE_LOG_RELATIVE_PATH.'/'.CONTROL_PLANE_OVERSIZED_RUN_ID.CONTROL_PLANE_WIRE_LOG_EXTENSION),
        json_encode([
            'at' => now()->toIso8601String(),
            'type' => 'llm.response_body',
            'raw_body' => $rawBody,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
    );

    $response = $this->actingAs($user)->get(route('admin.ai.runs.wire-log-entry', [
        'runId' => CONTROL_PLANE_OVERSIZED_RUN_ID,
        'entryNumber' => 1,
    ]));

    $response->assertOk();
    $streamedContent = $response->streamedContent();

    expect($response->headers->get('content-type'))->toStartWith('application/json')
        ->and($streamedContent)->toContain('"type":"llm.response_body"')
        ->and($streamedContent)->toContain('"raw_body":"'.substr($rawBody, 0, 128));
});
