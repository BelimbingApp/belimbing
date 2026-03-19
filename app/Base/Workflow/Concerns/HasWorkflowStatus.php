<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Concerns;

use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Services\StatusManager;
use App\Base\Workflow\Services\WorkflowEngine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Trait for Eloquent models that participate in the workflow engine.
 *
 * The model must have a `status` string column and implement the
 * abstract `flow()` method returning its flow identifier.
 */
trait HasWorkflowStatus
{
    /**
     * Return the flow identifier for this model (e.g., 'leave_application').
     */
    abstract public function flow(): string;

    /**
     * Get the StatusConfig for this model's current status.
     */
    public function currentStatusConfig(): ?StatusConfig
    {
        return app(StatusManager::class)->getStatus($this->flow(), $this->getAttribute('status'));
    }

    /**
     * Get transitions available from the current status.
     *
     * @return Collection<int, StatusTransition>
     */
    public function availableTransitions(): Collection
    {
        return app(WorkflowEngine::class)->availableTransitions(
            $this->flow(),
            $this->getAttribute('status')
        );
    }

    /**
     * Transition this model to a new status.
     */
    public function transitionTo(string $toCode, TransitionContext $context): TransitionResult
    {
        return app(WorkflowEngine::class)->transition($this, $this->flow(), $toCode, $context);
    }

    /**
     * Get the full status history timeline for this model.
     *
     * @return Collection<int, StatusHistory>
     */
    public function statusTimeline(): Collection
    {
        return StatusHistory::timeline($this->flow(), $this->getKey());
    }
}
