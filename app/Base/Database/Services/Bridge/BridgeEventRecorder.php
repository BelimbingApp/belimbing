<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Models\BridgeEvent;
use App\Base\Database\Models\BridgePlan;
use App\Base\Database\Models\BridgeReceipt;
use App\Base\Database\Models\BridgeReceiveGrant;

class BridgeEventRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(string $action, ?BridgePlan $plan = null, array $metadata = [], ?string $error = null): BridgeEvent
    {
        $receipt = $plan?->receipt;

        return BridgeEvent::query()->create([
            'package_id' => $receipt?->package_id,
            'plan_hash' => $plan?->plan_hash,
            'action' => $action,
            'actor_id' => auth()->id(),
            'source_instance_id' => $receipt?->source_instance_id,
            'target_instance_id' => $receipt?->target_instance_id,
            'scope_name' => $receipt?->scope_name,
            'metadata' => $metadata,
            'error_summary' => $error === null ? null : mb_substr($error, 0, 2000),
            'created_at' => now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function recordReceipt(string $action, BridgeReceipt $receipt, array $metadata = []): BridgeEvent
    {
        return $this->create([
            'package_id' => $receipt->package_id,
            'action' => $action,
            'source_instance_id' => $receipt->source_instance_id,
            'target_instance_id' => $receipt->target_instance_id,
            'scope_name' => $receipt->scope_name,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function recordGrant(
        string $action,
        BridgeReceiveGrant $grant,
        array $metadata = [],
    ): BridgeEvent {
        return $this->create([
            'action' => $action,
            'actor_id' => $grant->issued_by_actor_id,
            'source_instance_id' => $grant->expected_source_instance_id,
            'target_instance_id' => $grant->target_instance_id,
            'scope_name' => $grant->scope_name,
            'metadata' => [
                'grant_id' => $grant->grant_id,
                'status' => $grant->status,
                'expires_at' => $grant->expires_at->toIso8601String(),
                ...$metadata,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $metadata
     */
    public function recordExport(string $action, array $manifest, array $metadata = []): BridgeEvent
    {
        return $this->create([
            'package_id' => $manifest['package_id'] ?? null,
            'action' => $action,
            'source_instance_id' => $manifest['source']['id'] ?? null,
            'target_instance_id' => $manifest['target']['id'] ?? null,
            'scope_name' => $manifest['scope']['name'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function create(array $attributes): BridgeEvent
    {
        return BridgeEvent::query()->create([
            'package_id' => null,
            'plan_hash' => null,
            'actor_id' => auth()->id(),
            'source_instance_id' => null,
            'target_instance_id' => null,
            'scope_name' => null,
            'metadata' => [],
            'error_summary' => null,
            'created_at' => now('UTC'),
            ...$attributes,
        ]);
    }
}
