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

afterEach(function (): void {
    File::delete(storage_path('app/ai/wire-logs/'.CONTROL_PLANE_OVERSIZED_RUN_ID.'.jsonl'));
    File::delete(storage_path('app/ai/wire-logs/'.CONTROL_PLANE_WINDOWED_RUN_ID.'.jsonl'));
});

it('renders a bounded wire-log preview for oversized run logs', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    Company::provisionLicensee('Test Licensee');
    Employee::provisionLara();

    $user = createAdminUser();

    AiRun::unguarded(fn () => AiRun::query()->create([
        'id' => CONTROL_PLANE_OVERSIZED_RUN_ID,
        'employee_id' => Employee::LARA_ID,
        'session_id' => 'sess_control_plane_oversized',
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Succeeded,
        'provider_name' => 'test-provider',
        'model' => 'test-model',
        'started_at' => now(),
        'finished_at' => now(),
    ]));

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

    File::ensureDirectoryExists(storage_path('app/ai/wire-logs'));
    File::put(
        storage_path('app/ai/wire-logs/'.CONTROL_PLANE_OVERSIZED_RUN_ID.'.jsonl'),
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
        ->assertSee('Payload preview omitted because this wire-log entry exceeds 64 KB.')
        ->assertSee('Preview truncated.');
});

it('navigates wire-log windows for large runs', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    Company::provisionLicensee('Test Licensee');
    Employee::provisionLara();

    $user = createAdminUser();

    AiRun::unguarded(fn () => AiRun::query()->create([
        'id' => CONTROL_PLANE_WINDOWED_RUN_ID,
        'employee_id' => Employee::LARA_ID,
        'session_id' => 'sess_control_plane_windowed',
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Succeeded,
        'provider_name' => 'test-provider',
        'model' => 'test-model',
        'started_at' => now(),
        'finished_at' => now(),
    ]));

    $lines = [];

    for ($index = 1; $index <= 245; $index++) {
        $lines[] = json_encode([
            'at' => now()->copy()->addSeconds($index)->toIso8601String(),
            'type' => 'llm.stream_line',
            'raw_line' => 'entry-'.$index,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    File::ensureDirectoryExists(storage_path('app/ai/wire-logs'));
    File::put(
        storage_path('app/ai/wire-logs/'.CONTROL_PLANE_WINDOWED_RUN_ID.'.jsonl'),
        implode("\n", $lines)."\n",
    );

    Livewire::actingAs($user)
        ->test(ControlPlane::class)
        ->set('inspectRunId', CONTROL_PLANE_WINDOWED_RUN_ID)
        ->call('inspectRun')
        ->assertSet('wireLogLimit', 100)
        ->assertSee('Showing entries 1-100 of 245 retained wire-log entries.')
        ->assertSee('entry-1')
        ->assertSee('entry-100')
        ->set('wireLogLimit', 50)
        ->assertDispatched('wire-log-window-changed')
        ->call('lastWireLogEntries', 195)
        ->assertDispatched('wire-log-window-changed')
        ->assertSee('Showing entries 196-245 of 245 retained wire-log entries.')
        ->assertSee('entry-245')
        ->set('wireLogStartEntry', '101')
        ->call('jumpToWireLogEntry', 245)
        ->assertDispatched('wire-log-window-changed')
        ->assertSee('Showing entries 101-150 of 245 retained wire-log entries.')
        ->assertSee('entry-101')
        ->assertSee('entry-150');
});
