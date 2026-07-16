<?php

namespace App\Base\Workflow\Services;

use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\TransitionOutboxMessage;
use Illuminate\Database\Eloquent\Model;

class TransitionOutbox
{
    public function enqueue(
        Model $model,
        StatusTransition $transition,
        StatusHistory $history,
        TransitionContext $context,
        TransitionPayload $payload,
    ): TransitionOutboxMessage {
        return TransitionOutboxMessage::query()->firstOrCreate(
            ['event_key' => 'workflow.transition.completed:'.$history->id],
            [
                'event_type' => 'workflow.transition.completed',
                'payload' => [
                    'model_class' => $model::class,
                    'model_id' => $model->getKey(),
                    'model_key_name' => $model->getKeyName(),
                    'model_snapshot' => $model->getAttributes(),
                    'transition_id' => $transition->id,
                    'transition_snapshot' => $transition->getAttributes(),
                    'history_id' => $history->id,
                    'history_snapshot' => $history->getAttributes(),
                    'context' => [
                        'actor' => [
                            'type' => $context->actor->type->value,
                            'id' => $context->actor->id,
                            'company_id' => $context->actor->companyId,
                            'acting_for_user_id' => $context->actor->actingForUserId,
                            'attributes' => $context->actor->attributes,
                        ],
                        'comment' => $context->comment,
                        'comment_tag' => $context->commentTag,
                        'assignees' => $context->assignees,
                        'attachments' => $context->attachments,
                        'metadata' => $context->metadata,
                    ],
                    'payload' => [
                        'flow' => $payload->flow,
                        'flow_model' => $payload->flowModel,
                        'flow_id' => $payload->flowId,
                        'from_status' => $payload->fromStatus,
                        'to_status' => $payload->toStatus,
                        'actor_id' => $payload->actorId,
                        'actor_role' => $payload->actorRole,
                        'actor_department' => $payload->actorDepartment,
                        'assignees' => $payload->assignees,
                        'comment' => $payload->comment,
                        'comment_tag' => $payload->commentTag,
                        'attachments' => $payload->attachments,
                        'metadata' => $payload->metadata,
                        'transitioned_at' => $payload->transitionedAt->toIso8601String(),
                    ],
                ],
                'available_at' => now(),
            ],
        );
    }
}
