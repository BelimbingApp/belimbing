<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Services;

use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates status transitions for workflow participants.
 *
 * Entry point for all status change operations. Coordinates validation,
 * model update, history recording, action execution, and hook firing.
 *
 * Transaction policy:
 * - Inside DB transaction: model status update + history + action_class
 * - Outside (best-effort): Hooks::fireAfter / event dispatch
 */
class WorkflowEngine
{
    public function __construct(
        private readonly TransitionManager $transitionManager,
        private readonly TransitionValidator $validator,
        private readonly Container $container,
    ) {}

    /**
     * Transition a model to a new status.
     *
     * @param  Model  $model  The workflow participant
     * @param  string  $flow  The flow identifier
     * @param  string  $toCode  The target status code
     * @param  TransitionContext  $context  Actor, comment, metadata, etc.
     */
    public function transition(Model $model, string $flow, string $toCode, TransitionContext $context): TransitionResult
    {
        $fromCode = $model->getAttribute('status');

        $transition = $this->transitionManager->getTransition($flow, $fromCode, $toCode);

        if ($transition === null) {
            return TransitionResult::failure(
                "No transition defined from '{$fromCode}' to '{$toCode}' in flow '{$flow}'."
            );
        }

        $guardResult = $this->validator->validate($transition, $context->actor, $model);

        if (! $guardResult->allowed) {
            return TransitionResult::failure($guardResult->reason ?? 'Transition denied.');
        }

        $previousHistory = StatusHistory::latest($flow, $model->getKey());
        $now = Carbon::now();
        $tat = $previousHistory?->transitioned_at
            ? (int) $previousHistory->transitioned_at->diffInSeconds($now)
            : null;

        /** @var StatusHistory $history */
        $history = DB::transaction(function () use ($model, $flow, $toCode, $context, $transition, $now, $tat): StatusHistory {
            $model->setAttribute('status', $toCode);
            $model->save();

            $history = StatusHistory::query()->create([
                'flow' => $flow,
                'flow_id' => $model->getKey(),
                'status' => $toCode,
                'tat' => $tat,
                'actor_id' => $context->actor->id,
                'actor_role' => $context->actor->attributes['role'] ?? null,
                'actor_department' => $context->actor->attributes['department'] ?? null,
                'actor_company' => $context->actor->attributes['company'] ?? null,
                'assignees' => $context->assignees,
                'comment' => $context->comment,
                'comment_tag' => $context->commentTag,
                'attachments' => $context->attachments,
                'metadata' => $context->metadata,
                'transitioned_at' => $now,
            ]);

            $this->executeAction($transition, $model, $context);

            return $history;
        });

        $payload = new TransitionPayload(
            flow: $flow,
            flowModel: $model::class,
            flowId: $model->getKey(),
            fromStatus: $fromCode,
            toStatus: $toCode,
            actorId: $context->actor->id,
            actorRole: $context->actor->attributes['role'] ?? null,
            actorDepartment: $context->actor->attributes['department'] ?? null,
            assignees: $context->assignees,
            comment: $context->comment,
            commentTag: $context->commentTag,
            attachments: $context->attachments,
            metadata: $context->metadata,
            transitionedAt: $now,
        );

        TransitionCompleted::dispatch($flow, $model, $transition, $history, $context, $payload);

        return TransitionResult::success($history);
    }

    /**
     * Get the transitions available from the model's current status.
     *
     * @return Collection<int, StatusTransition>
     */
    public function availableTransitions(string $flow, string $currentStatus): Collection
    {
        return $this->transitionManager->getAvailableTransitions($flow, $currentStatus);
    }

    /**
     * Execute the transition's action_class (if defined) inside the DB transaction.
     *
     * Passes the full TransitionContext so actions can access actor, comment,
     * attachments, and metadata without coupling to individual fields.
     */
    private function executeAction(StatusTransition $transition, Model $model, TransitionContext $context): void
    {
        if ($transition->action_class === null) {
            return;
        }

        /** @var TransitionAction $action */
        $action = $this->container->make($transition->action_class);
        $action->execute($model, $transition, $context);
    }
}
