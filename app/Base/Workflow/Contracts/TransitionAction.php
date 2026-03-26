<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Contracts;

use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;

interface TransitionAction
{
    /**
     * Execute logic after a transition succeeds (inside the DB transaction).
     *
     * For external API calls, dispatch a queued job instead of making
     * synchronous calls — avoid holding the transaction open on network I/O.
     *
     * @param  Model  $model  The workflow participant (e.g., LeaveApplication)
     * @param  StatusTransition  $transition  The transition that was executed
     * @param  TransitionContext  $context  The transition context (actor, comment, attachments, metadata)
     */
    public function execute(Model $model, StatusTransition $transition, TransitionContext $context): void;
}
