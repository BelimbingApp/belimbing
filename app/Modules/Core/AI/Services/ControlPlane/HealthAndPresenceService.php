<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\PresenceState;
use App\Modules\Core\AI\Enums\ToolHealthState;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\ToolReadinessService;

/**
 * Computes health snapshots that separate readiness, health, and presence.
 *
 * Readiness = can this thing be used in principle? (config, auth)
 * Health = is it behaving correctly right now? (recent verification)
 * Presence = is it live/active/reachable? (recent activity, sessions)
 *
 * These three dimensions never collapse into one badge.
 */
class HealthAndPresenceService
{
    private const PROVIDER_LAST_TEST_AT_SUFFIX = '.last_test_at';

    private const PROVIDER_LAST_TEST_SUCCESS_SUFFIX = '.last_test_success';

    /**
     * Threshold in minutes: agent considered active if session activity within this window.
     */
    private const ACTIVE_THRESHOLD_MINUTES = 15;

    /**
     * Threshold in minutes: agent considered idle (not offline) within this window.
     */
    private const IDLE_THRESHOLD_MINUTES = 60;

    /**
     * Threshold in hours: tool verification considered fresh within this window.
     */
    private const VERIFICATION_FRESH_HOURS = 24;

    public function __construct(
        private readonly ToolReadinessService $toolReadinessService,
        private readonly SessionManager $sessionManager,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Get a health snapshot for a specific tool.
     */
    public function toolSnapshot(string $toolName): HealthSnapshot
    {
        $readinessSnapshot = $this->toolReadinessService->snapshot($toolName);
        $readiness = $readinessSnapshot['readiness'];
        $lastVerified = $readinessSnapshot['lastVerified'];

        $health = $this->computeToolHealth($readiness, $lastVerified);
        $presence = $this->computeToolPresence($readiness);

        return new HealthSnapshot(
            targetType: ControlPlaneTarget::Tool,
            targetId: $toolName,
            readiness: $readiness,
            health: $health,
            presence: $presence,
            explanation: $this->buildToolExplanation($toolName, $readiness, $lastVerified),
            measuredAt: now()->toIso8601String(),
        );
    }

    /**
     * Get health snapshots for all known tools.
     *
     * @return list<HealthSnapshot>
     */
    public function allToolSnapshots(): array
    {
        $allSnapshots = $this->toolReadinessService->allSnapshots();
        $snapshots = [];

        foreach ($allSnapshots as $name => $data) {
            $readiness = $data['readiness'];
            $lastVerified = $data['lastVerified'];
            $health = $this->computeToolHealth($readiness, $lastVerified);
            $presence = $this->computeToolPresence($readiness);

            $snapshots[] = new HealthSnapshot(
                targetType: ControlPlaneTarget::Tool,
                targetId: $name,
                readiness: $readiness,
                health: $health,
                presence: $presence,
                explanation: $this->buildToolExplanation($name, $readiness, $lastVerified),
                measuredAt: now()->toIso8601String(),
            );
        }

        return $snapshots;
    }

    /**
     * Get a health snapshot for an agent.
     *
     * @param  int  $employeeId  Agent employee ID
     */
    public function agentSnapshot(int $employeeId): HealthSnapshot
    {
        $presence = $this->computeAgentPresence($employeeId);

        // Agent readiness is always READY if they exist in the system
        $readiness = ToolReadiness::READY;

        // Agent health is derived from recent run outcomes
        $health = $this->computeAgentHealth($employeeId);

        return new HealthSnapshot(
            targetType: ControlPlaneTarget::Agent,
            targetId: (string) $employeeId,
            readiness: $readiness,
            health: $health,
            presence: $presence,
            explanation: $this->buildAgentExplanation($employeeId, $health, $presence),
            measuredAt: now()->toIso8601String(),
        );
    }

    /**
     * Get a health snapshot for a provider.
     *
     * @param  string  $providerName  Provider name
     */
    public function providerSnapshot(string $providerName): HealthSnapshot
    {
        $lastTest = $this->settings->get('ai.providers.'.$providerName.self::PROVIDER_LAST_TEST_AT_SUFFIX);
        $lastTestSuccess = (bool) $this->settings->get('ai.providers.'.$providerName.self::PROVIDER_LAST_TEST_SUCCESS_SUFFIX, false);

        $readiness = ToolReadiness::READY;
        $health = $this->computeProviderHealth($lastTest, $lastTestSuccess);
        $presence = $lastTest !== null ? PresenceState::Active : PresenceState::Offline;

        return new HealthSnapshot(
            targetType: ControlPlaneTarget::Provider,
            targetId: $providerName,
            readiness: $readiness,
            health: $health,
            presence: $presence,
            explanation: $this->buildProviderExplanation($providerName, $lastTest, $lastTestSuccess),
            measuredAt: now()->toIso8601String(),
        );
    }

    /**
     * Compute tool health from readiness and verification history.
     *
     * @param  array{at: string, success: bool}|null  $lastVerified
     */
    private function computeToolHealth(ToolReadiness $readiness, ?array $lastVerified): ToolHealthState
    {
        // Not ready means health is unknown — can't assess what isn't available
        if ($readiness !== ToolReadiness::READY) {
            return ToolHealthState::UNKNOWN;
        }

        // No verification ever performed
        if ($lastVerified === null) {
            return ToolHealthState::UNKNOWN;
        }

        // Recent failure = failing
        if (! $lastVerified['success']) {
            return ToolHealthState::FAILING;
        }

        // Check freshness of the verification
        $verifiedAt = strtotime($lastVerified['at']);

        if ($verifiedAt === false) {
            return ToolHealthState::UNKNOWN;
        }

        $hoursSince = (time() - $verifiedAt) / 3600;

        if ($hoursSince <= self::VERIFICATION_FRESH_HOURS) {
            return ToolHealthState::HEALTHY;
        }

        // Stale verification — degraded confidence
        return ToolHealthState::DEGRADED;
    }

    /**
     * Compute tool presence from configuration state.
     *
     * Tools are always "present" if they are ready — they don't have
     * activity signals the way agents do. A tool that is not ready
     * is considered offline.
     */
    private function computeToolPresence(ToolReadiness $readiness): PresenceState
    {
        return $readiness === ToolReadiness::READY
            ? PresenceState::Active
            : PresenceState::Offline;
    }

    /**
     * Compute agent presence from recent session activity.
     */
    private function computeAgentPresence(int $employeeId): PresenceState
    {
        $sessions = $this->sessionManager->list($employeeId);

        if ($sessions === []) {
            return PresenceState::Offline;
        }

        // Most recent session is first (list returns newest-first)
        $latestSession = $sessions[0];
        $lastActivityTimestamp = $latestSession->lastActivityAt->getTimestamp();
        $minutesAgo = (time() - $lastActivityTimestamp) / 60;

        if ($minutesAgo <= self::ACTIVE_THRESHOLD_MINUTES) {
            return PresenceState::Active;
        }

        if ($minutesAgo <= self::IDLE_THRESHOLD_MINUTES) {
            return PresenceState::Idle;
        }

        return PresenceState::Offline;
    }

    /**
     * Compute agent health from recent session run outcomes.
     */
    private function computeAgentHealth(int $employeeId): ToolHealthState
    {
        $recentRuns = AiRun::query()
            ->where('employee_id', $employeeId)
            ->latest('started_at')
            ->limit(5)
            ->get();

        if ($recentRuns->isEmpty()) {
            return ToolHealthState::UNKNOWN;
        }

        $errorCount = $recentRuns->whereNotNull('error_type')->count();

        if ($errorCount === 0) {
            return ToolHealthState::HEALTHY;
        }

        if ($errorCount / $recentRuns->count() < 0.5) {
            return ToolHealthState::DEGRADED;
        }

        return ToolHealthState::FAILING;
    }

    /**
     * Compute provider health from test history.
     */
    private function computeProviderHealth(?string $lastTestAt, bool $lastTestSuccess): ToolHealthState
    {
        if ($lastTestAt === null) {
            return ToolHealthState::UNKNOWN;
        }

        if (! $lastTestSuccess) {
            return ToolHealthState::FAILING;
        }

        $hoursSince = (time() - strtotime($lastTestAt)) / 3600;

        if ($hoursSince <= self::VERIFICATION_FRESH_HOURS) {
            return ToolHealthState::HEALTHY;
        }

        return ToolHealthState::DEGRADED;
    }

    /**
     * Build a human-readable explanation for tool state.
     *
     * @param  array{at: string, success: bool}|null  $lastVerified
     */
    private function buildToolExplanation(
        string $toolName,
        ToolReadiness $readiness,
        ?array $lastVerified,
    ): string {
        if ($readiness !== ToolReadiness::READY) {
            return "Tool '{$toolName}' is {$readiness->label()} — cannot assess health or presence.";
        }

        $parts = ["Tool '{$toolName}' is ready."];

        if ($lastVerified !== null) {
            $parts[] = $lastVerified['success']
                ? "Last verified successfully at {$lastVerified['at']}."
                : "Last verification failed at {$lastVerified['at']}.";
        } else {
            $parts[] = 'No verification performed yet.';
        }

        return implode(' ', $parts);
    }

    private function buildProviderExplanation(string $providerName, ?string $lastTest, bool $lastTestSuccess): string
    {
        if ($lastTest === null) {
            return "Provider '{$providerName}' has not been tested yet.";
        }

        if ($lastTestSuccess) {
            return "Provider '{$providerName}' last tested successfully at {$lastTest}.";
        }

        return "Provider '{$providerName}' last test failed at {$lastTest}.";
    }

    /**
     * Build a human-readable explanation for agent state.
     */
    private function buildAgentExplanation(
        int $employeeId,
        ToolHealthState $health,
        PresenceState $presence,
    ): string {
        $parts = ["Agent #{$employeeId} is {$presence->label()}."];

        $parts[] = match ($health) {
            ToolHealthState::HEALTHY => 'Recent runs completed successfully.',
            ToolHealthState::DEGRADED => 'Some recent runs encountered errors.',
            ToolHealthState::FAILING => 'Most recent runs failed.',
            ToolHealthState::UNKNOWN => 'No recent run data available.',
        };

        return implode(' ', $parts);
    }
}
