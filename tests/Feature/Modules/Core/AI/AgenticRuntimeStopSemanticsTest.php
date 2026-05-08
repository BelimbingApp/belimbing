<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Contracts\Tool;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Tests\Support\MakesRuntimeResponses;

uses(MakesRuntimeResponses::class);

const AGENTIC_RUNTIME_STOP_SESSION = 'sess_runtime_stop_semantics';
const AGENTIC_RUNTIME_STOP_PROVIDER_TEXT = 'Provider already answered.';

function runtimeStopTool(int &$executionCount): Tool
{
    return new StubTool(
        toolName: 'stop_gap_tool',
        toolDescription: 'Should not execute after Stop.',
        schema: ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]],
        execute: function () use (&$executionCount): ToolResult {
            $executionCount++;

            return ToolResult::success('executed');
        },
    );
}

it('renders provider output after Stop but does not execute new tool work', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    $turn = AiRun::query()->create([
        'employee_id' => $employee->id,
        'session_id' => AGENTIC_RUNTIME_STOP_SESSION,
        'status' => AiRunStatus::Running,
        'current_phase' => RunPhase::AwaitingLlm,
    ]);
    $turn->requestCancel('User pressed stop');

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::on(fn (ChatRequest $request): bool => $request->isCancelRequested()))
        ->andReturn((function (): Generator {
            yield ['type' => 'content_delta', 'text' => AGENTIC_RUNTIME_STOP_PROVIDER_TEXT];
            yield [
                'type' => 'tool_call_delta',
                'index' => 0,
                'id' => 'call_should_not_run',
                'name' => 'stop_gap_tool',
                'arguments_delta' => '{"input":"late"}',
            ];
            yield [
                'type' => 'done',
                'finish_reason' => 'tool_calls',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'latency_ms' => 123,
            ];
        })());

    $executionCount = 0;
    $runtime = $this->makeAgenticRuntime(
        $llmClient,
        toolRegistry: $this->makeToolRegistry(runtimeStopTool($executionCount)),
    );

    $events = iterator_to_array($runtime->runStream(
        [$this->makeMessage('user', 'Please use a tool')],
        $employee->id,
        $turn->id,
        'You are Lara.',
        sessionId: AGENTIC_RUNTIME_STOP_SESSION,
    ), false);

    expect(collect($events)->firstWhere('event', 'delta')['data']['text'] ?? null)->toBe(AGENTIC_RUNTIME_STOP_PROVIDER_TEXT)
        ->and(collect($events)->where('event', 'status')->pluck('data.phase')->all())->toContain(RunPhase::Cancelled->value)
        ->and(collect($events)->where('event', 'status')->pluck('data.phase')->all())->not->toContain('tool_started')
        ->and(collect($events)->pluck('event')->all())->not->toContain('done')
        ->and($executionCount)->toBe(0);
});
