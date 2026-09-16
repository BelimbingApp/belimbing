<?php

namespace App\Base\Workflow\Human;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Workflow\Human\Contracts\HumanActionHandler;
use App\Base\Workflow\Human\DTO\AvailableHumanAction;
use App\Base\Workflow\Human\DTO\HumanActionDefinition;
use App\Base\Workflow\Human\DTO\HumanActionRequest;
use App\Base\Workflow\Human\DTO\HumanActionResult;
use App\Base\Workflow\Models\HumanActionRequestRecord;
use App\Base\Workflow\Models\ProcessRun;
use App\Base\Workflow\Models\ProcessWorkItem;
use App\Base\Workflow\Process\Enums\ProcessRunStatus;
use App\Base\Workflow\Process\Enums\ProcessWorkStatus;
use App\Base\Workflow\Process\ProcessCoordinator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class HumanActionService
{
    public function __construct(
        private readonly HumanActionRegistry $actions,
        private readonly AuthorizationService $authz,
        private readonly TenantContext $tenants,
        private readonly ProcessCoordinator $processes,
        private readonly Container $container,
    ) {}

    /** @return list<AvailableHumanAction> */
    public function available(Actor $actor, Model $subject): array
    {
        $tenantId = $this->assertTenantBoundary($actor, $subject);
        $resource = $this->resource($subject, $tenantId);
        $available = [];

        foreach ($this->actions->for($subject) as $definition) {
            if (! $this->authz->can($actor, $definition->capability, $resource)->allowed) {
                continue;
            }

            if ($definition->executorKey === null) {
                $available[] = new AvailableHumanAction($definition->key, $definition->label);

                continue;
            }

            $item = ProcessWorkItem::query()
                ->select('base_workflow_process_work_items.*')
                ->join('base_workflow_process_runs', 'base_workflow_process_runs.id', '=', 'base_workflow_process_work_items.process_run_id')
                ->where('base_workflow_process_runs.scope_type', 'tenant')
                ->where('base_workflow_process_runs.tenant_id', $tenantId)
                ->where('base_workflow_process_runs.subject_type', $subject::class)
                ->where('base_workflow_process_runs.subject_id', (string) $subject->getKey())
                ->where('base_workflow_process_runs.status', ProcessRunStatus::RUNNING->value)
                ->where('base_workflow_process_work_items.executor_key', $definition->executorKey)
                ->where('base_workflow_process_work_items.status', ProcessWorkStatus::AVAILABLE->value)
                ->orderByDesc('base_workflow_process_runs.id')->first();

            if ($item !== null) {
                $available[] = new AvailableHumanAction($definition->key, $definition->label, true, null,
                    (int) $item->process_run_id, (int) $item->id, (int) $item->version);
            }
        }

        return $available;
    }

    public function execute(Actor $actor, Model $subject, HumanActionRequest $request): HumanActionResult
    {
        $tenantId = $this->assertTenantBoundary($actor, $subject);
        $definition = $this->actions->get($subject, $request->actionKey);
        $intentHash = $this->intentHash($actor, $subject, $request);

        try {
            return DB::transaction(function () use ($actor, $subject, $request, $tenantId, $definition, $intentHash): HumanActionResult {
                $lockedSubject = $subject->newModelQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                $this->assertTenantBoundary($actor, $lockedSubject);
                $this->authz->authorize($actor, $definition->capability, $this->resource($lockedSubject, $tenantId));

                $existing = HumanActionRequestRecord::query()
                    ->where('tenant_id', $tenantId)->where('idempotency_key', $request->idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->intent_hash, $intentHash)) {
                        throw new HumanActionConflictException('The idempotency key was already used for different intent.');
                    }
                    if ($existing->completed_at === null || $existing->result === null) {
                        throw new HumanActionConflictException('The matching human action is still being processed.');
                    }

                    return HumanActionResult::fromArray($existing->result, true);
                }

                $this->assertSubjectVersion($lockedSubject, $request->expectedSubjectVersion);
                $this->assertRequestShape($definition, $request);
                $this->assertProcessSubject($definition, $request, $lockedSubject, $tenantId);

                $record = HumanActionRequestRecord::query()->create([
                    'tenant_id' => $tenantId, 'idempotency_key' => $request->idempotencyKey, 'intent_hash' => $intentHash,
                    'action_key' => $definition->key, 'subject_type' => $lockedSubject::class,
                    'subject_id' => (string) $lockedSubject->getKey(), 'process_run_id' => $request->processRunId,
                    'work_item_id' => $request->workItemId, 'actor_type' => $actor->type->value, 'actor_id' => $actor->id,
                ]);

                $handler = $this->container->make($definition->handler);
                if (! $handler instanceof HumanActionHandler) {
                    throw new HumanActionException("Human action handler [{$definition->handler}] is invalid.");
                }
                $outcome = $handler->handle($actor, $lockedSubject, $request);
                $workItem = null;
                if ($definition->executorKey !== null) {
                    $workItem = $this->processes->completeHumanWork(
                        $tenantId, $request->processRunId, $request->workItemId, $request->expectedWorkItemVersion,
                        $definition->executorKey, $outcome->output, $outcome->outcome, $outcome->resultRef,
                        ['action_key' => $definition->key, 'request_id' => $record->id,
                            'actor_type' => $actor->type->value, 'actor_id' => $actor->id],
                    );
                }

                $result = new HumanActionResult($definition->key, $outcome->output, $outcome->outcome,
                    $outcome->resultRef, $workItem?->id);
                $record->forceFill(['result' => $result->toArray(), 'completed_at' => now()])->save();

                return $result;
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent request can win the unique tenant/key insert after
            // our initial lookup. Its committed result is the retry response.
            $record = HumanActionRequestRecord::query()
                ->where('tenant_id', $tenantId)->where('idempotency_key', $request->idempotencyKey)->first();
            if ($record === null || $record->completed_at === null || $record->result === null) {
                throw $exception;
            }
            if (! hash_equals($record->intent_hash, $intentHash)) {
                throw new HumanActionConflictException('The idempotency key was already used for different intent.', previous: $exception);
            }

            return HumanActionResult::fromArray($record->result, true);
        }
    }

    public function subjectVersion(Model $subject): string
    {
        if ($subject->getKey() === null) {
            throw new HumanActionException('A human action subject must be persisted.');
        }

        $persisted = $subject->newModelQuery()->whereKey($subject->getKey())->firstOrFail();
        $attributes = $persisted->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    private function assertTenantBoundary(Actor $actor, Model $subject): int
    {
        $tenantId = $this->tenants->requireTenantId();
        $subjectTenant = $subject->getAttributes()['tenant_id'] ?? null;
        if ($subjectTenant === null || (int) $subjectTenant !== $tenantId || $actor->tenantId !== $tenantId) {
            throw new HumanActionException('The actor or subject is outside the current tenant boundary.');
        }

        return $tenantId;
    }

    private function assertSubjectVersion(Model $subject, string $expected): void
    {
        if ($expected === '' || ! hash_equals($this->subjectVersion($subject), $expected)) {
            throw new HumanActionConflictException('The subject changed after the action was displayed.');
        }
    }

    private function assertRequestShape(HumanActionDefinition $definition, HumanActionRequest $request): void
    {
        if (trim($request->idempotencyKey) === '') {
            throw new HumanActionException('A human action requires an idempotency key.');
        }
        if ($definition->executorKey !== null
            && ($request->processRunId === null || $request->workItemId === null || $request->expectedWorkItemVersion === null)) {
            throw new HumanActionException('This human action requires a process run, work item, and expected work version.');
        }
    }

    private function resource(Model $subject, int $tenantId): ResourceContext
    {
        $companyId = $subject->getAttributes()['company_id'] ?? null;

        return new ResourceContext($subject::class, $subject->getKey(), $companyId === null ? null : (int) $companyId,
            tenantId: $tenantId);
    }

    private function assertProcessSubject(HumanActionDefinition $definition, HumanActionRequest $request, Model $subject, int $tenantId): void
    {
        if ($definition->executorKey === null) {
            return;
        }

        $belongs = ProcessRun::query()
            ->whereKey($request->processRunId)
            ->where('scope_type', 'tenant')
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subject::class)
            ->where('subject_id', (string) $subject->getKey())
            ->exists();
        if (! $belongs) {
            throw new HumanActionException('The process run does not belong to this subject.');
        }
    }

    private function intentHash(Actor $actor, Model $subject, HumanActionRequest $request): string
    {
        $intent = ['subject_type' => $subject::class, 'subject_id' => (string) $subject->getKey(),
            'actor_type' => $actor->type->value, 'actor_id' => $actor->id,
            'action_key' => $request->actionKey, 'process_run_id' => $request->processRunId,
            'work_item_id' => $request->workItemId, 'payload' => $request->payload];
        $sort = function (&$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as &$nested) {
                $sort($nested);
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($intent);

        return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
    }
}
