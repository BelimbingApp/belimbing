<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\ControlPlane\PolicyDecision;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\DTO\WorkspaceValidationResult;
use App\Modules\Core\AI\Enums\PolicyLayer;
use App\Modules\Core\AI\Enums\PolicyVerdict;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use App\Modules\Core\AI\Services\ControlPlane\PolicyEvaluationService;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;

const PES_TOOL_NAME = 'bash';
const PES_SUBJECT = 'agent:lara';
const PES_EMPLOYEE_ID = 1;
const PES_SAFE_URL = 'https://example.com/api/data';
const PES_BLOCKED_URL = 'http://169.254.169.254/latest/meta-data';
const PES_WORKSPACE_PATH_PREFIX = '/tmp/workspace/';
const PES_FRAMEWORK_RESOURCE_PATH = '/resources/agents';

function makePesMocks(): array
{
    return [
        'toolReadiness' => Mockery::mock(ToolReadinessService::class),
        'ssrfGuard' => Mockery::mock(BrowserSsrfGuard::class),
        'workspaceResolver' => Mockery::mock(WorkspaceResolver::class),
        'workspaceValidator' => Mockery::mock(WorkspaceValidator::class),
    ];
}

function makePesService(array $mocks): PolicyEvaluationService
{
    return new PolicyEvaluationService(
        $mocks['toolReadiness'],
        $mocks['ssrfGuard'],
        $mocks['workspaceResolver'],
        $mocks['workspaceValidator'],
    );
}

// ------------------------------------------------------------------
// evaluateToolUse
// ------------------------------------------------------------------

describe('evaluateToolUse', function () {
    it('allows tool use when all layers pass', function () {
        $mocks = makePesMocks();
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::READY);

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(PES_TOOL_NAME, PES_SUBJECT);

        expect($decision)->toBeInstanceOf(PolicyDecision::class)
            ->and($decision->verdict)->toBe(PolicyVerdict::Allow)
            ->and($decision->isAllowed())->toBeTrue()
            ->and($decision->action)->toBe('use_tool:'.PES_TOOL_NAME);
    });

    it('denies tool use when capability check fails (unauthorized)', function () {
        $mocks = makePesMocks();
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::UNAUTHORIZED);

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(PES_TOOL_NAME, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::Capability)
            ->and($decision->isAllowed())->toBeFalse()
            ->and($decision->reason)->toContain(PES_SUBJECT)
            ->and($decision->reason)->toContain(PES_TOOL_NAME);
    });

    it('denies tool use when readiness is unavailable', function () {
        $mocks = makePesMocks();
        // First call for capability layer returns non-UNAUTHORIZED (passes),
        // second call for readiness layer returns UNAVAILABLE.
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::UNAVAILABLE);

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(PES_TOOL_NAME, PES_SUBJECT);

        // Capability check uses readiness() too — UNAVAILABLE != UNAUTHORIZED, so it passes.
        // Readiness layer: UNAVAILABLE → Deny.
        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::Readiness)
            ->and($decision->reason)->toContain('unavailable');
    });

    it('denies tool use when readiness is unconfigured', function () {
        $mocks = makePesMocks();
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::UNCONFIGURED);

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(PES_TOOL_NAME, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::Readiness)
            ->and($decision->reason)->toContain('unconfigured');
    });

    it('denies when URL context fails SSRF guard', function () {
        $mocks = makePesMocks();
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::READY);
        $mocks['ssrfGuard']->shouldReceive('validate')
            ->with(PES_BLOCKED_URL)
            ->andReturn('SSRF: blocked internal IP address');

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(
            PES_TOOL_NAME,
            PES_SUBJECT,
            ['url' => PES_BLOCKED_URL],
        );

        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::DataNetwork)
            ->and($decision->reason)->toContain('blocked');
    });

    it('allows when URL context passes SSRF guard', function () {
        $mocks = makePesMocks();
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::READY);
        $mocks['ssrfGuard']->shouldReceive('validate')
            ->with(PES_SAFE_URL)
            ->andReturn(true);

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(
            PES_TOOL_NAME,
            PES_SUBJECT,
            ['url' => PES_SAFE_URL],
        );

        expect($decision->verdict)->toBe(PolicyVerdict::Allow);
    });

    it('accumulates layer results through the evaluation chain', function () {
        $mocks = makePesMocks();
        $mocks['toolReadiness']->shouldReceive('readiness')
            ->with(PES_TOOL_NAME)
            ->andReturn(ToolReadiness::READY);

        $service = makePesService($mocks);
        $decision = $service->evaluateToolUse(PES_TOOL_NAME, PES_SUBJECT);

        // Two layers evaluated: capability + readiness
        expect($decision->layerResults)->toHaveCount(2)
            ->and($decision->layerResults[0]['layer'])->toBe(PolicyLayer::Capability->value)
            ->and($decision->layerResults[1]['layer'])->toBe(PolicyLayer::Readiness->value);
    });
});

// ------------------------------------------------------------------
// evaluateNetworkAccess
// ------------------------------------------------------------------

describe('evaluateNetworkAccess', function () {
    it('allows access for a safe URL', function () {
        $mocks = makePesMocks();
        $mocks['ssrfGuard']->shouldReceive('validate')
            ->with(PES_SAFE_URL)
            ->andReturn(true);

        $service = makePesService($mocks);
        $decision = $service->evaluateNetworkAccess(PES_SAFE_URL, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Allow)
            ->and($decision->action)->toBe('network_access:'.PES_SAFE_URL);
    });

    it('denies access for a blocked URL', function () {
        $mocks = makePesMocks();
        $mocks['ssrfGuard']->shouldReceive('validate')
            ->with(PES_BLOCKED_URL)
            ->andReturn('SSRF: internal IP blocked');

        $service = makePesService($mocks);
        $decision = $service->evaluateNetworkAccess(PES_BLOCKED_URL, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::DataNetwork)
            ->and($decision->reason)->toContain('blocked');
    });
});

// ------------------------------------------------------------------
// evaluateWorkspaceValidity
// ------------------------------------------------------------------

describe('evaluateWorkspaceValidity', function () {
    it('allows when workspace is valid with no warnings', function () {
        $manifest = new WorkspaceManifest(
            employeeId: PES_EMPLOYEE_ID,
            workspacePath: PES_WORKSPACE_PATH_PREFIX.PES_EMPLOYEE_ID,
            isSystemAgent: true,
            frameworkResourcePath: PES_FRAMEWORK_RESOURCE_PATH,
            files: [],
        );
        $validResult = new WorkspaceValidationResult(
            valid: true,
            errors: [],
            warnings: [],
            loadOrder: [],
        );

        $mocks = makePesMocks();
        $mocks['workspaceResolver']->shouldReceive('resolve')
            ->with(PES_EMPLOYEE_ID)
            ->andReturn($manifest);
        $mocks['workspaceValidator']->shouldReceive('validate')
            ->with($manifest)
            ->andReturn($validResult);

        $service = makePesService($mocks);
        $decision = $service->evaluateWorkspaceValidity(PES_EMPLOYEE_ID, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Allow)
            ->and($decision->action)->toBe('workspace_validity:'.PES_EMPLOYEE_ID);
    });

    it('degrades when workspace is valid but has warnings', function () {
        $manifest = new WorkspaceManifest(
            employeeId: PES_EMPLOYEE_ID,
            workspacePath: PES_WORKSPACE_PATH_PREFIX.PES_EMPLOYEE_ID,
            isSystemAgent: true,
            frameworkResourcePath: PES_FRAMEWORK_RESOURCE_PATH,
            files: [],
        );
        $warningResult = new WorkspaceValidationResult(
            valid: true,
            errors: [],
            warnings: ['Optional file tools.md is missing'],
            loadOrder: [],
        );

        $mocks = makePesMocks();
        $mocks['workspaceResolver']->shouldReceive('resolve')
            ->with(PES_EMPLOYEE_ID)
            ->andReturn($manifest);
        $mocks['workspaceValidator']->shouldReceive('validate')
            ->with($manifest)
            ->andReturn($warningResult);

        $service = makePesService($mocks);
        $decision = $service->evaluateWorkspaceValidity(PES_EMPLOYEE_ID, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Degrade)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::DataNetwork)
            ->and($decision->reason)->toContain('warnings')
            ->and($decision->context['warnings'])->toBe(['Optional file tools.md is missing']);
    });

    it('denies when workspace validation fails', function () {
        $manifest = new WorkspaceManifest(
            employeeId: PES_EMPLOYEE_ID,
            workspacePath: PES_WORKSPACE_PATH_PREFIX.PES_EMPLOYEE_ID,
            isSystemAgent: true,
            frameworkResourcePath: PES_FRAMEWORK_RESOURCE_PATH,
            files: [],
        );
        $failedResult = new WorkspaceValidationResult(
            valid: false,
            errors: ['Required file system-prompt.md is missing', 'Required file identity.md is missing'],
            warnings: [],
            loadOrder: [],
        );

        $mocks = makePesMocks();
        $mocks['workspaceResolver']->shouldReceive('resolve')
            ->with(PES_EMPLOYEE_ID)
            ->andReturn($manifest);
        $mocks['workspaceValidator']->shouldReceive('validate')
            ->with($manifest)
            ->andReturn($failedResult);

        $service = makePesService($mocks);
        $decision = $service->evaluateWorkspaceValidity(PES_EMPLOYEE_ID, PES_SUBJECT);

        expect($decision->verdict)->toBe(PolicyVerdict::Deny)
            ->and($decision->decidingLayer)->toBe(PolicyLayer::DataNetwork)
            ->and($decision->isAllowed())->toBeFalse()
            ->and($decision->reason)->toContain('system-prompt.md')
            ->and($decision->context['errors'])->toHaveCount(2);
    });
});
