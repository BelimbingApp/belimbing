<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Types of operational telemetry events.
 *
 * Used by the telemetry pipeline to classify events so they can be
 * correlated, filtered, and inspected across runs, sessions, and dispatches.
 */
enum TelemetryEventType: string
{
    case RunStarted = 'run_started';
    case RunCompleted = 'run_completed';
    case RunFailed = 'run_failed';
    case ToolInvoked = 'tool_invoked';
    case ProviderFallback = 'provider_fallback';
    case ProviderTest = 'provider_test';
    case StreamStarted = 'stream_started';
    case StreamCompleted = 'stream_completed';
    case StreamFailed = 'stream_failed';
    case LifecycleAction = 'lifecycle_action';
    case PolicyDecision = 'policy_decision';
    case HealthCheck = 'health_check';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::RunStarted => 'Run Started',
            self::RunCompleted => 'Run Completed',
            self::RunFailed => 'Run Failed',
            self::ToolInvoked => 'Tool Invoked',
            self::ProviderFallback => 'Provider Fallback',
            self::ProviderTest => 'Provider Test',
            self::StreamStarted => 'Stream Started',
            self::StreamCompleted => 'Stream Completed',
            self::StreamFailed => 'Stream Failed',
            self::LifecycleAction => 'Lifecycle Action',
            self::PolicyDecision => 'Policy Decision',
            self::HealthCheck => 'Health Check',
        };
    }

    /**
     * Severity level (for filtering and alerting).
     */
    public function severity(): string
    {
        return match ($this) {
            self::RunFailed, self::StreamFailed => 'error',
            self::ProviderFallback, self::PolicyDecision => 'warning',
            default => 'info',
        };
    }
}
