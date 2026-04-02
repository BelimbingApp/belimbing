<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\ControlPlane;

use App\Modules\Core\AI\Enums\PolicyLayer;
use App\Modules\Core\AI\Enums\PolicyVerdict;

/**
 * Result of a layered policy evaluation.
 *
 * Explains which policy layer produced the verdict and why,
 * making denials and degradations transparent to operators.
 */
final readonly class PolicyDecision
{
    /**
     * @param  PolicyVerdict  $verdict  Allow, deny, or degrade
     * @param  PolicyLayer  $decidingLayer  The policy layer that produced the verdict
     * @param  string  $reason  Human-readable explanation for the decision
     * @param  string  $subject  The subject of the policy check (user, agent, etc.)
     * @param  string  $action  The action being evaluated
     * @param  array<string, mixed>  $context  Additional context supplied to the evaluation
     * @param  list<array{layer: string, verdict: string, reason: string}>  $layerResults  Results from each evaluated layer
     */
    public function __construct(
        public PolicyVerdict $verdict,
        public PolicyLayer $decidingLayer,
        public string $reason,
        public string $subject,
        public string $action,
        public array $context,
        public array $layerResults,
    ) {}

    /**
     * Create an allow decision with no restrictions.
     */
    public static function allow(
        string $subject,
        string $action,
        array $context = [],
        array $layerResults = [],
    ): self {
        return new self(
            verdict: PolicyVerdict::Allow,
            decidingLayer: PolicyLayer::Operator,
            reason: 'All policy layers passed.',
            subject: $subject,
            action: $action,
            context: $context,
            layerResults: $layerResults,
        );
    }

    /**
     * Create a denial decision from a specific layer.
     */
    public static function deny(
        PolicyLayer $layer,
        string $reason,
        string $subject,
        string $action,
        array $context = [],
        array $layerResults = [],
    ): self {
        return new self(
            verdict: PolicyVerdict::Deny,
            decidingLayer: $layer,
            reason: $reason,
            subject: $subject,
            action: $action,
            context: $context,
            layerResults: $layerResults,
        );
    }

    /**
     * Create a degraded decision from a specific layer.
     */
    public static function degrade(
        PolicyLayer $layer,
        string $reason,
        string $subject,
        string $action,
        array $context = [],
        array $layerResults = [],
    ): self {
        return new self(
            verdict: PolicyVerdict::Degrade,
            decidingLayer: $layer,
            reason: $reason,
            subject: $subject,
            action: $action,
            context: $context,
            layerResults: $layerResults,
        );
    }

    /**
     * Whether the action is allowed (fully or with degradation).
     */
    public function isAllowed(): bool
    {
        return $this->verdict !== PolicyVerdict::Deny;
    }

    /**
     * @return array{verdict: string, deciding_layer: string, reason: string, subject: string, action: string, context: array<string, mixed>, layer_results: list<array{layer: string, verdict: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'deciding_layer' => $this->decidingLayer->value,
            'reason' => $this->reason,
            'subject' => $this->subject,
            'action' => $this->action,
            'context' => $this->context,
            'layer_results' => $this->layerResults,
        ];
    }
}
