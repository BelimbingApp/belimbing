<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Models\DataShareEvent;
use App\Base\Database\Models\DataSharePlan;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;

class DataShareEventRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(string $action, ?DataSharePlan $plan = null, array $metadata = [], ?string $error = null): DataShareEvent
    {
        $receipt = $plan?->receipt;

        return DataShareEvent::query()->create([
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
    public function recordReceipt(string $action, DataShareReceipt $receipt, array $metadata = []): DataShareEvent
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
    public function recordOffer(
        string $action,
        DataShareTransferOffer $offer,
        array $metadata = [],
    ): DataShareEvent {
        return $this->create([
            'package_id' => $offer->package_id,
            'action' => $action,
            'actor_id' => $offer->published_by_actor_id,
            'source_instance_id' => $offer->source_instance_id,
            'scope_name' => $offer->scope_name,
            'metadata' => [
                'offer_id' => $offer->offer_id,
                'status' => $offer->status,
                'package_sha256' => $offer->package_sha256,
                'bytes' => $offer->bytes,
                'expires_at' => $offer->expires_at->toIso8601String(),
                ...$metadata,
            ],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function create(array $attributes): DataShareEvent
    {
        return DataShareEvent::query()->create([
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
