<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\DTO\Orchestration\RoutingDecision;
use App\Modules\Core\AI\DTO\Orchestration\RoutingRequest;

/**
 * First-class task routing engine.
 *
 * Decides whether work stays local, routes to another agent, or uses a
 * specialized skill pack. Evaluates structured capability contracts,
 * explicit preferences, and policy constraints to produce a deterministic
 * RoutingDecision with reasons.
 *
 * Routing is a service — not a side effect of a tool call.
 */
class TaskRoutingService
{
    public function __construct(
        private readonly AgentCapabilityCatalog $catalog,
        private readonly OrchestrationPolicyService $policy,
    ) {}

    /**
     * Route a task request to the best target.
     *
     * Routing strategy:
     * 1. If the request specifies a preferred agent, validate and use it
     * 2. Try structured capability matching across the catalog
     * 3. Fall back to legacy keyword matching if no structured data exists
     * 4. Default to local execution if no agent matches
     */
    public function route(RoutingRequest $request): RoutingDecision
    {
        // Explicit agent preference — bypass auto-matching
        if ($request->hasPreferredAgent()) {
            return $this->routeToPreferredAgent($request);
        }

        // Auto-match: try structured capabilities first, then keyword fallback
        $descriptors = $this->catalog->delegableDescriptorsForCurrentUser();

        if ($descriptors === []) {
            return RoutingDecision::local(['No delegable agents available for the current user.']);
        }

        $structuredMatch = $this->matchByStructuredCapabilities($request, $descriptors);

        if ($structuredMatch !== null) {
            return $structuredMatch;
        }

        $keywordMatch = $this->matchByKeywords($request, $descriptors);

        if ($keywordMatch !== null) {
            return $keywordMatch;
        }

        return RoutingDecision::local([
            'No agent matched the task by structured capabilities or keyword overlap.',
            'Task will execute locally.',
        ]);
    }

    /**
     * Route to an explicitly preferred agent, validating policy.
     */
    private function routeToPreferredAgent(RoutingRequest $request): RoutingDecision
    {
        $descriptor = $this->catalog->descriptorFor($request->preferredAgentId);

        if ($descriptor === null) {
            return RoutingDecision::local([
                'Preferred agent (ID: '.$request->preferredAgentId.') is not available.',
                'Falling back to local execution.',
            ]);
        }

        if (! $this->policy->canDelegate($request->requestingEmployeeId, $descriptor->employeeId)) {
            return RoutingDecision::local([
                'Policy does not allow delegation from agent '.$request->requestingEmployeeId
                    .' to agent '.$descriptor->employeeId.'.',
            ]);
        }

        return RoutingDecision::agent(
            agentEmployeeId: $descriptor->employeeId,
            agentName: $descriptor->name,
            confidenceScore: 100,
            reasons: ['Explicitly requested agent (ID: '.$descriptor->employeeId.').'],
            meta: ['routing_method' => 'explicit_preference'],
        );
    }

    /**
     * Match using structured capability descriptors.
     *
     * Scores each agent by domain overlap, task-type match, and specialty
     * keywords. Returns the best match if it exceeds the confidence threshold.
     *
     * @param  list<AgentCapabilityDescriptor>  $descriptors
     */
    private function matchByStructuredCapabilities(RoutingRequest $request, array $descriptors): ?RoutingDecision
    {
        $bestDescriptor = null;
        $bestScore = 0;
        $bestReasons = [];

        foreach ($descriptors as $descriptor) {
            if (! $this->policy->hasRoutableCapabilities($descriptor)) {
                continue;
            }

            if (! $this->policy->canDelegate($request->requestingEmployeeId, $descriptor->employeeId)) {
                continue;
            }

            [$score, $reasons] = $this->scoreStructuredMatch($request, $descriptor);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDescriptor = $descriptor;
                $bestReasons = $reasons;
            }
        }

        if ($bestDescriptor === null || $bestScore === 0) {
            return null;
        }

        return RoutingDecision::agent(
            agentEmployeeId: $bestDescriptor->employeeId,
            agentName: $bestDescriptor->name,
            confidenceScore: $bestScore,
            reasons: $bestReasons,
            meta: ['routing_method' => 'structured_capability'],
        );
    }

    /**
     * Fall back to keyword-based matching on display summaries.
     *
     * This preserves the LaraCapabilityMatcher scoring approach as a
     * degraded path for agents without structured capability data.
     *
     * @param  list<AgentCapabilityDescriptor>  $descriptors
     */
    private function matchByKeywords(RoutingRequest $request, array $descriptors): ?RoutingDecision
    {
        $bestDescriptor = null;
        $bestScore = 0;

        foreach ($descriptors as $descriptor) {
            if (! $this->policy->canDelegate($request->requestingEmployeeId, $descriptor->employeeId)) {
                continue;
            }

            $score = $this->scoreKeywordMatch($request->task, $descriptor->displaySummary ?? '');

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDescriptor = $descriptor;
            }
        }

        if ($bestDescriptor === null || $bestScore === 0) {
            return null;
        }

        return RoutingDecision::agent(
            agentEmployeeId: $bestDescriptor->employeeId,
            agentName: $bestDescriptor->name,
            confidenceScore: min($bestScore * 10, 80),
            reasons: [
                'Matched by keyword overlap (score: '.$bestScore.').',
                'Agent lacks structured capabilities — using legacy matching.',
            ],
            meta: ['routing_method' => 'keyword_fallback'],
        );
    }

    /**
     * Score a structured capability match.
     *
     * @return array{0: int, 1: list<string>} [score, reasons]
     */
    private function scoreStructuredMatch(RoutingRequest $request, AgentCapabilityDescriptor $descriptor): array
    {
        $score = 0;
        $reasons = [];

        // Task type match (highest signal)
        if ($request->taskType !== null && $descriptor->supportsTaskType($request->taskType)) {
            $score += 40;
            $reasons[] = 'Supports task type: '.$request->taskType.'.';
        }

        // Domain match from constraints
        $requestedDomains = $request->constraints['domains'] ?? [];

        if (is_array($requestedDomains)) {
            foreach ($requestedDomains as $domain) {
                if ($descriptor->supportsDomain($domain)) {
                    $score += 30;
                    $reasons[] = 'Supports domain: '.$domain.'.';
                }
            }
        }

        // Specialty keyword overlap with task text
        $taskLower = mb_strtolower($request->task);

        foreach ($descriptor->specialties as $specialty) {
            if (str_contains($taskLower, mb_strtolower($specialty))) {
                $score += 15;
                $reasons[] = 'Specialty keyword match: '.$specialty.'.';
            }
        }

        // Domain keyword overlap with task text
        foreach ($descriptor->domains as $domain) {
            if (str_contains($taskLower, mb_strtolower($domain))) {
                $score += 10;
                $reasons[] = 'Domain keyword found in task: '.$domain.'.';
            }
        }

        return [$score, $reasons];
    }

    /**
     * Score keyword overlap between task and display summary.
     *
     * Mirrors the legacy LaraCapabilityMatcher scoring approach.
     */
    private function scoreKeywordMatch(string $task, string $summary): int
    {
        $normalizedTask = strtolower((string) preg_replace('/[^a-z0-9\s]/i', ' ', $task));
        $normalizedSummary = strtolower($summary);

        $keywords = array_filter(
            array_unique(explode(' ', $normalizedTask)),
            fn (string $keyword): bool => strlen($keyword) >= 3,
        );

        $score = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedSummary, $keyword)) {
                $score++;
            }
        }

        return $score;
    }
}
