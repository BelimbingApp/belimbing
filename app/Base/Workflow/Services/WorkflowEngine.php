<?php

namespace App\Base\Workflow\Services;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Workflow\Contracts\TransitionAction;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\DTO\TransitionResult;
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
 * - Inside DB transaction: model status update, history, action class, and outbox record
 * - After commit: semantic audit is best-effort and the durable event gets an
 *   immediate delivery attempt; the reconciler retries failed deliveries
 */
class WorkflowEngine
{
    public function __construct(
        private readonly TransitionManager $transitionManager,
        private readonly TransitionValidator $validator,
        private readonly Container $container,
        private readonly TransitionOutbox $outbox,
        private readonly TransitionOutboxDispatcher $outboxDispatcher,
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
        if ($model->getKey() === null) {
            return TransitionResult::failure('A workflow transition requires a persisted model.');
        }

        /**
         * Lock and re-read the participant before deriving the source edge. Two
         * workers may hold stale model instances, but only the first transition
         * from the persisted status can commit.
         *
         * @var TransitionResult|array{model: Model, transition: StatusTransition, history: StatusHistory, payload: TransitionPayload, outbox_id: int} $outcome
         */
        $outcome = DB::transaction(function () use ($model, $flow, $toCode, $context): TransitionResult|array {
            $lockedModel = $model->newModelQuery()
                ->whereKey($model->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $fromCode = $lockedModel->getAttribute('status');

            if (! is_string($fromCode) || $fromCode === '') {
                return TransitionResult::failure('The workflow participant has no current status.');
            }

            $transition = $this->transitionManager->getTransition($flow, $fromCode, $toCode);

            if ($transition === null) {
                return TransitionResult::failure(
                    "No transition defined from '{$fromCode}' to '{$toCode}' in flow '{$flow}'."
                );
            }

            $guardResult = $this->validator->validate($transition, $context->actor, $lockedModel);

            if (! $guardResult->allowed) {
                return TransitionResult::failure($guardResult->reason ?? 'Transition denied.');
            }

            $previousHistory = StatusHistory::latest($flow, (int) $lockedModel->getKey());
            $now = Carbon::now();
            $tat = $previousHistory?->transitioned_at
                ? (int) $previousHistory->transitioned_at->diffInSeconds($now)
                : null;

            $lockedModel->setAttribute('status', $toCode);
            $lockedModel->save();

            $history = StatusHistory::query()->create([
                'flow' => $flow,
                'flow_id' => $lockedModel->getKey(),
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

            $this->executeAction($transition, $lockedModel, $context);
            $lockedModel->refresh();

            $payload = new TransitionPayload(
                flow: $flow,
                flowModel: $lockedModel::class,
                flowId: (int) $lockedModel->getKey(),
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
            $outbox = $this->outbox->enqueue($lockedModel, $transition, $history, $context, $payload);

            return [
                'model' => $lockedModel,
                'transition' => $transition,
                'history' => $history,
                'payload' => $payload,
                'outbox_id' => (int) $outbox->id,
            ];
        });

        if ($outcome instanceof TransitionResult) {
            return $outcome;
        }

        $model->setRawAttributes($outcome['model']->getAttributes(), true);

        try {
            $this->recordTransitionSemanticAction($outcome['model'], $outcome['transition'], $context, $outcome['payload']);
        } catch (\Throwable $exception) {
            report($exception);
        }

        try {
            $this->outboxDispatcher->deliver($outcome['outbox_id']);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return TransitionResult::success($outcome['history']);
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
