<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Models\DataShareEvent;
use App\Base\Database\Models\DataSharePlan;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use Throwable;

class DataShareEventRecorder
{
    /** Metadata keys safe to persist on failure or export rows. */
    private const array SAFE_CONTEXT_KEYS = [
        'offer_id',
        'package_id',
        'bytes',
        'endpoint_host',
        'scope_name',
        'status',
        'package_sha256',
    ];

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

    /**
     * Record a failure without payload values or bearer secrets.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordFailure(string $action, array $context, Throwable $e): DataShareEvent
    {
        $safe = $this->safeContext($context);

        return $this->create([
            'action' => $action,
            'package_id' => isset($safe['package_id']) && is_string($safe['package_id']) ? $safe['package_id'] : null,
            'scope_name' => isset($safe['scope_name']) && is_string($safe['scope_name']) ? $safe['scope_name'] : null,
            'metadata' => $safe,
            'error_summary' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }

    /**
     * Record a package-level action that is not tied to a plan or receipt row.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordPackage(string $action, string $packageId, string $scopeName, array $metadata = []): DataShareEvent
    {
        return $this->create([
            'action' => $action,
            'package_id' => $packageId,
            'scope_name' => $scopeName,
            'metadata' => $this->safeContext($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function safeContext(array $context): array
    {
        $safe = [];

        foreach (self::SAFE_CONTEXT_KEYS as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
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
