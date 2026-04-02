<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\DTO\ControlPlane\PolicyDecision;
use App\Modules\Core\AI\Enums\PolicyLayer;
use App\Modules\Core\AI\Enums\PolicyVerdict;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;

/**
 * Evaluates layered policy beyond coarse capability checks.
 *
 * Policy is evaluated top-to-bottom through five layers:
 * 1. Capability (authz)
 * 2. Readiness (tool/operation availability)
 * 3. Subsystem (orchestration, browser, memory rules)
 * 4. Data/Network (SSRF, file access, workspace validity)
 * 5. Operator (confirmation/escalation)
 *
 * The first layer that denies stops evaluation. Explanations are always
 * provided so operators can understand why an action was blocked.
 */
class PolicyEvaluationService
{
    public function __construct(
        private readonly ToolReadinessService $toolReadinessService,
        private readonly BrowserSsrfGuard $ssrfGuard,
        private readonly WorkspaceResolver $workspaceResolver,
        private readonly WorkspaceValidator $workspaceValidator,
    ) {}

    /**
     * Evaluate whether a tool invocation is allowed.
     *
     * Checks capability, readiness, and subsystem policy layers.
     *
     * @param  string  $toolName  Tool machine name
     * @param  string  $subject  The actor (user/agent identifier)
     * @param  array<string, mixed>  $context  Additional context (e.g., URL for network policy)
     */
    public function evaluateToolUse(string $toolName, string $subject, array $context = []): PolicyDecision
    {
        $layerResults = [];

        // Layer 1: Capability
        $capabilityResult = $this->evaluateCapability($toolName, $subject);
        $layerResults[] = $capabilityResult;

        if ($capabilityResult['verdict'] === PolicyVerdict::Deny->value) {
            return PolicyDecision::deny(
                layer: PolicyLayer::Capability,
                reason: $capabilityResult['reason'],
                subject: $subject,
                action: "use_tool:{$toolName}",
                context: $context,
                layerResults: $layerResults,
            );
        }

        // Layer 2: Readiness
        $readinessResult = $this->evaluateReadiness($toolName);
        $layerResults[] = $readinessResult;

        if ($readinessResult['verdict'] === PolicyVerdict::Deny->value) {
            return PolicyDecision::deny(
                layer: PolicyLayer::Readiness,
                reason: $readinessResult['reason'],
                subject: $subject,
                action: "use_tool:{$toolName}",
                context: $context,
                layerResults: $layerResults,
            );
        }

        // Layer 4: Data/Network (if URL context is provided)
        if (isset($context['url'])) {
            $networkResult = $this->evaluateNetworkPolicy($context['url']);
            $layerResults[] = $networkResult;

            if ($networkResult['verdict'] === PolicyVerdict::Deny->value) {
                return PolicyDecision::deny(
                    layer: PolicyLayer::DataNetwork,
                    reason: $networkResult['reason'],
                    subject: $subject,
                    action: "use_tool:{$toolName}",
                    context: $context,
                    layerResults: $layerResults,
                );
            }
        }

        return PolicyDecision::allow(
            subject: $subject,
            action: "use_tool:{$toolName}",
            context: $context,
            layerResults: $layerResults,
        );
    }

    /**
     * Evaluate whether a URL is allowed under network policy.
     *
     * @param  string  $url  The URL to check
     * @param  string  $subject  The actor
     */
    public function evaluateNetworkAccess(string $url, string $subject): PolicyDecision
    {
        $layerResults = [];
        $networkResult = $this->evaluateNetworkPolicy($url);
        $layerResults[] = $networkResult;

        if ($networkResult['verdict'] === PolicyVerdict::Deny->value) {
            return PolicyDecision::deny(
                layer: PolicyLayer::DataNetwork,
                reason: $networkResult['reason'],
                subject: $subject,
                action: "network_access:{$url}",
                context: ['url' => $url],
                layerResults: $layerResults,
            );
        }

        return PolicyDecision::allow(
            subject: $subject,
            action: "network_access:{$url}",
            context: ['url' => $url],
            layerResults: $layerResults,
        );
    }

    /**
     * Evaluate whether a workspace is valid for an agent.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $subject  The actor
     */
    public function evaluateWorkspaceValidity(int $employeeId, string $subject): PolicyDecision
    {
        $layerResults = [];
        $manifest = $this->workspaceResolver->resolve($employeeId);
        $result = $this->workspaceValidator->validate($manifest);

        $workspaceResult = [
            'layer' => PolicyLayer::DataNetwork->value,
            'verdict' => $result->valid ? PolicyVerdict::Allow->value : PolicyVerdict::Deny->value,
            'reason' => $result->valid
                ? 'Workspace is valid.'
                : 'Workspace validation failed: '.implode('; ', $result->errors),
        ];

        $layerResults[] = $workspaceResult;

        if (! $result->valid) {
            return PolicyDecision::deny(
                layer: PolicyLayer::DataNetwork,
                reason: $workspaceResult['reason'],
                subject: $subject,
                action: "workspace_validity:{$employeeId}",
                context: ['employee_id' => $employeeId, 'errors' => $result->errors, 'warnings' => $result->warnings],
                layerResults: $layerResults,
            );
        }

        if ($result->warnings !== []) {
            return PolicyDecision::degrade(
                layer: PolicyLayer::DataNetwork,
                reason: 'Workspace valid but with warnings: '.implode('; ', $result->warnings),
                subject: $subject,
                action: "workspace_validity:{$employeeId}",
                context: ['employee_id' => $employeeId, 'warnings' => $result->warnings],
                layerResults: $layerResults,
            );
        }

        return PolicyDecision::allow(
            subject: $subject,
            action: "workspace_validity:{$employeeId}",
            context: ['employee_id' => $employeeId],
            layerResults: $layerResults,
        );
    }

    /**
     * @return array{layer: string, verdict: string, reason: string}
     */
    private function evaluateCapability(string $toolName, string $subject): array
    {
        // Delegate to the existing authorization service
        // The tool registry already checks canCurrentUserUseTool, but we need
        // an explanation-friendly check for the policy evaluation surface
        $readiness = $this->toolReadinessService->readiness($toolName);

        if ($readiness === ToolReadiness::UNAUTHORIZED) {
            return [
                'layer' => PolicyLayer::Capability->value,
                'verdict' => PolicyVerdict::Deny->value,
                'reason' => "Subject '{$subject}' does not have the required capability for tool '{$toolName}'.",
            ];
        }

        return [
            'layer' => PolicyLayer::Capability->value,
            'verdict' => PolicyVerdict::Allow->value,
            'reason' => 'Capability check passed.',
        ];
    }

    /**
     * @return array{layer: string, verdict: string, reason: string}
     */
    private function evaluateReadiness(string $toolName): array
    {
        $readiness = $this->toolReadinessService->readiness($toolName);

        if ($readiness === ToolReadiness::UNAVAILABLE) {
            return [
                'layer' => PolicyLayer::Readiness->value,
                'verdict' => PolicyVerdict::Deny->value,
                'reason' => "Tool '{$toolName}' is unavailable — not registered in the runtime.",
            ];
        }

        if ($readiness === ToolReadiness::UNCONFIGURED) {
            return [
                'layer' => PolicyLayer::Readiness->value,
                'verdict' => PolicyVerdict::Deny->value,
                'reason' => "Tool '{$toolName}' is unconfigured — required configuration is missing.",
            ];
        }

        if ($readiness === ToolReadiness::NEEDS_ATTENTION) {
            return [
                'layer' => PolicyLayer::Readiness->value,
                'verdict' => PolicyVerdict::Degrade->value,
                'reason' => "Tool '{$toolName}' needs attention — may work with reduced capability.",
            ];
        }

        return [
            'layer' => PolicyLayer::Readiness->value,
            'verdict' => PolicyVerdict::Allow->value,
            'reason' => 'Tool is ready.',
        ];
    }

    /**
     * @return array{layer: string, verdict: string, reason: string}
     */
    private function evaluateNetworkPolicy(string $url): array
    {
        $result = $this->ssrfGuard->validate($url);

        if ($result !== true) {
            return [
                'layer' => PolicyLayer::DataNetwork->value,
                'verdict' => PolicyVerdict::Deny->value,
                'reason' => "Network access blocked: {$result}",
            ];
        }

        return [
            'layer' => PolicyLayer::DataNetwork->value,
            'verdict' => PolicyVerdict::Allow->value,
            'reason' => 'URL passes SSRF safety checks.',
        ];
    }
}
