<?php

namespace App\Base\Workflow\Services;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $this->recordTransitionSemanticAction($model, $transition, $context, $payload);

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

    private function recordTransitionSemanticAction(
        Model $model,
        StatusTransition $transition,
        TransitionContext $context,
        TransitionPayload $payload,
    ): void {
        $subject = $this->modelAuditSubject($model);
        $recordLabel = $this->subjectLabel($subject, $model);
        $fromLabel = $this->statusLabel($payload->flow, $payload->fromStatus);
        $toLabel = $this->statusLabel($payload->flow, $payload->toStatus);

        app(SemanticActionRecorder::class)->record(
            event: 'workflow.transition.completed',
            summary: __('Transitioned :record from :from to :to', [
                'record' => $recordLabel,
                'from' => $fromLabel,
                'to' => $toLabel,
            ]),
            source: __('Workflow'),
            subject: $subject,
            surface: 'workflow.'.$payload->flow,
            context: [
                'flow' => $payload->flow,
                'flow_model' => $payload->flowModel,
                'flow_id' => $payload->flowId,
                'from_status' => $payload->fromStatus,
                'to_status' => $payload->toStatus,
                'from_label' => $fromLabel,
                'to_label' => $toLabel,
                'transition_id' => $transition->id,
                'transition_label' => $transition->resolveLabel(),
                'actor_type' => $context->actor->type->value,
                'actor_id' => $context->actor->id,
                'actor_role' => $context->actor->attributes['role'] ?? null,
                'actor_department' => $context->actor->attributes['department'] ?? null,
                'comment_present' => $context->comment !== null && trim($context->comment) !== '',
                'comment_tag' => $context->commentTag,
                'assignee_count' => count($context->assignees ?? []),
                'attachment_count' => count($context->attachments ?? []),
                'metadata_keys' => array_keys($context->metadata ?? []),
            ],
        );
    }

    /** @return array{name?: string, id?: int|string, identifier?: string|null} */
    private function modelAuditSubject(Model $model): array
    {
        if (method_exists($model, 'getAuditSubject')) {
            $subject = $model->getAuditSubject();

            if (is_array($subject)
                && isset($subject['name'], $subject['id'])
                && is_string($subject['name'])
                && $subject['name'] !== ''
                && $subject['id'] !== null
                && $subject['id'] !== '') {
                $payload = [
                    'name' => $subject['name'],
                    'id' => is_int($subject['id']) ? $subject['id'] : (string) $subject['id'],
                ];

                if (($subject['identifier'] ?? null) !== null && $subject['identifier'] !== '') {
                    $payload['identifier'] = (string) $subject['identifier'];
                }

                return $payload;
            }
        }

        $id = $model->getKey();

        if ($id === null || $id === '') {
            return [];
        }

        return [
            'name' => Str::snake(class_basename($model)),
            'id' => is_int($id) ? $id : (string) $id,
        ];
    }

    /** @param  array{name?: string, id?: int|string, identifier?: string|null}  $subject */
    private function subjectLabel(array $subject, Model $model): string
    {
        $name = $subject['name'] ?? null;
        $id = $subject['id'] ?? null;

        if (is_string($name) && $name !== '' && $id !== null && $id !== '') {
            return Str::headline($name).'#'.$id;
        }

        return class_basename($model).'#'.$model->getKey();
    }

    private function statusLabel(string $flow, ?string $code): string
    {
        if ($code === null || $code === '') {
            return __('Unknown');
        }

        $label = StatusConfig::query()
            ->where('flow', $flow)
            ->where('code', $code)
            ->value('label');

        return is_string($label) && $label !== '' ? $label : Str::headline($code);
    }
}
