<?php

namespace App\Base\Workflow\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\TransitionOutboxMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * At-least-once transition event delivery with a short database lease.
 *
 * The event identity is the outbox row's unique event_key. Once delivered,
 * repeated reconciliation is a no-op. Listener-side effects should still use
 * the stable TransitionPayload/history id as their idempotency boundary.
 */
class TransitionOutboxDispatcher
{
    private const LEASE_SECONDS = 300;

    public function deliver(int|TransitionOutboxMessage $message): bool
    {
        $messageId = $message instanceof TransitionOutboxMessage ? (int) $message->getKey() : $message;
        $token = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($messageId, $token): ?TransitionOutboxMessage {
            $row = TransitionOutboxMessage::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($row === null || $row->delivered_at !== null) {
                return null;
            }

            if ($row->available_at->isFuture()) {
                return null;
            }

            if ($row->lease_token !== null && $row->lease_expires_at?->isFuture()) {
                return null;
            }

            $row->forceFill([
                'attempts' => $row->attempts + 1,
                'lease_token' => $token,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ])->save();

            return $row->refresh();
        });

        if ($claimed === null) {
            return TransitionOutboxMessage::query()->whereKey($messageId)->whereNotNull('delivered_at')->exists();
        }

        try {
            $event = $this->rehydrate($claimed);
            event($event);

            TransitionOutboxMessage::query()
                ->whereKey($claimed->id)
                ->where('lease_token', $token)
                ->update([
                    'delivered_at' => now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return true;
        } catch (Throwable $exception) {
            $delay = min(3600, 2 ** min(10, $claimed->attempts));

            TransitionOutboxMessage::query()
                ->whereKey($claimed->id)
                ->where('lease_token', $token)
                ->update([
                    'available_at' => now()->addSeconds($delay),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error' => Str::limit($exception->getMessage(), 4000, ''),
                    'updated_at' => now(),
                ]);

            report($exception);

            return false;
        }
    }

    public function deliverDue(int $limit = 100): int
    {
        $delivered = 0;

        $ids = TransitionOutboxMessage::query()
            ->whereNull('delivered_at')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('lease_token')->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');

        foreach ($ids as $id) {
            $delivered += $this->deliver((int) $id) ? 1 : 0;
        }

        return $delivered;
    }

    private function rehydrate(TransitionOutboxMessage $message): TransitionCompleted
    {
        $stored = $message->payload;
        $modelClass = $stored['model_class'] ?? null;

        if (! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new TransitionOutboxException('Transition outbox contains an invalid workflow model class.');
        }

        /** @var Model $prototype */
        $prototype = new $modelClass;
        $modelSnapshot = $stored['model_snapshot'] ?? null;

        if (is_array($modelSnapshot)) {
            $model = $prototype->newInstance();
            $this->fromSnapshot($model, $modelSnapshot);
        } else {
            // Compatibility for outbox rows written before model snapshots were
            // persisted. New rows always preserve the post-transition state.
            $model = $prototype->newQuery()->find($stored['model_id'] ?? null);

            if ($model === null) {
                $model = $prototype->newInstance();
                $model->setAttribute((string) ($stored['model_key_name'] ?? $model->getKeyName()), $stored['model_id'] ?? null);
            }
        }

        $transition = $this->rehydrateTransition($stored);
        $history = $this->rehydrateHistory($stored);

        $contextData = $stored['context'] ?? [];
        $actorData = $contextData['actor'] ?? [];
        $actor = new Actor(
            type: PrincipalType::from((string) ($actorData['type'] ?? 'guest')),
            id: (int) ($actorData['id'] ?? 0),
            companyId: isset($actorData['company_id']) ? (int) $actorData['company_id'] : null,
            actingForUserId: isset($actorData['acting_for_user_id']) ? (int) $actorData['acting_for_user_id'] : null,
            attributes: is_array($actorData['attributes'] ?? null) ? $actorData['attributes'] : [],
        );
        $context = new TransitionContext(
            actor: $actor,
            comment: $contextData['comment'] ?? null,
            commentTag: $contextData['comment_tag'] ?? null,
            assignees: $contextData['assignees'] ?? null,
            attachments: $contextData['attachments'] ?? null,
            metadata: $contextData['metadata'] ?? null,
        );

        $payloadData = $stored['payload'] ?? [];
        $payload = new TransitionPayload(
            flow: (string) ($payloadData['flow'] ?? ''),
            flowModel: (string) ($payloadData['flow_model'] ?? $modelClass),
            flowId: (int) ($payloadData['flow_id'] ?? 0),
            fromStatus: $payloadData['from_status'] ?? null,
            toStatus: (string) ($payloadData['to_status'] ?? ''),
            actorId: isset($payloadData['actor_id']) ? (int) $payloadData['actor_id'] : null,
            actorRole: $payloadData['actor_role'] ?? null,
            actorDepartment: $payloadData['actor_department'] ?? null,
            assignees: $payloadData['assignees'] ?? null,
            comment: $payloadData['comment'] ?? null,
            commentTag: $payloadData['comment_tag'] ?? null,
            attachments: $payloadData['attachments'] ?? null,
            metadata: $payloadData['metadata'] ?? null,
            transitionedAt: Carbon::parse((string) ($payloadData['transitioned_at'] ?? now()->toIso8601String())),
        );

        return new TransitionCompleted(
            $payload->flow,
            $model,
            $transition,
            $history,
            $context,
            $payload,
        );
    }

    /** @template TModel of Model @param TModel $model @param mixed $snapshot @return TModel */
    private function fromSnapshot(Model $model, mixed $snapshot): Model
    {
        if (! is_array($snapshot)) {
            throw new TransitionOutboxException('Transition outbox contains an invalid record snapshot.');
        }

        $model->setRawAttributes($snapshot, true);
        $model->exists = true;

        return $model;
    }

    /** @param array<string, mixed> $stored */
    private function rehydrateTransition(array $stored): StatusTransition
    {
        $snapshot = $stored['transition_snapshot'] ?? null;

        if (is_array($snapshot) && $snapshot !== []) {
            return $this->fromSnapshot(new StatusTransition, $snapshot);
        }

        return StatusTransition::query()->find($stored['transition_id'] ?? null)
            ?? throw new TransitionOutboxException('Transition outbox cannot resolve its workflow transition.');
    }

    /** @param array<string, mixed> $stored */
    private function rehydrateHistory(array $stored): StatusHistory
    {
        $snapshot = $stored['history_snapshot'] ?? null;

        if (is_array($snapshot) && $snapshot !== []) {
            return $this->fromSnapshot(new StatusHistory, $snapshot);
        }

        return StatusHistory::query()->find($stored['history_id'] ?? null)
            ?? throw new TransitionOutboxException('Transition outbox cannot resolve its workflow history.');
    }
}
