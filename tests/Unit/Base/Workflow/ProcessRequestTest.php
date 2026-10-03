<?php

use App\Base\Workflow\Process\CompleteHumanWorkRequest;
use App\Base\Workflow\Process\ProcessStartRequest;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('carries process start metadata through fluent request methods', function (): void {
    $availableAt = Carbon::parse('2026-10-03 12:30:00', 'UTC');

    $request = (new ProcessStartRequest('workflow.fixture'))
        ->withInput(['record' => 42])
        ->withIdempotencyKey('start-42')
        ->withSubject('fixture.record', 42)
        ->withDefinitionVersion(7)
        ->withCorrelationKey('fixture:42')
        ->withPriority(85)
        ->availableAt($availableAt);

    expect($request->definitionKey)->toBe('workflow.fixture')
        ->and($request->input)->toBe(['record' => 42])
        ->and($request->idempotencyKey)->toBe('start-42')
        ->and($request->subjectType)->toBe('fixture.record')
        ->and($request->subjectId)->toBe(42)
        ->and($request->definitionVersion)->toBe(7)
        ->and($request->correlationKey)->toBe('fixture:42')
        ->and($request->priority)->toBe(85)
        ->and($request->availableAt)->toBe($availableAt);
});

it('carries human work result metadata through fluent request methods', function (): void {
    $request = (new CompleteHumanWorkRequest(
        tenantId: 11,
        runId: 22,
        workItemId: 33,
        expectedVersion: 2,
        executorKey: 'human.review',
    ))
        ->withResult(['decision' => 'approved'], 'approved', 'record:33')
        ->withEventContext(['actor_id' => 44]);

    expect($request->tenantId)->toBe(11)
        ->and($request->runId)->toBe(22)
        ->and($request->workItemId)->toBe(33)
        ->and($request->expectedVersion)->toBe(2)
        ->and($request->executorKey)->toBe('human.review')
        ->and($request->output)->toBe(['decision' => 'approved'])
        ->and($request->outcome)->toBe('approved')
        ->and($request->resultRef)->toBe('record:33')
        ->and($request->eventContext)->toBe(['actor_id' => 44]);
});
