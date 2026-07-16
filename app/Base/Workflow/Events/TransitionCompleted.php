<?php

namespace App\Base\Workflow\Events;

use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a transition commits successfully.
 *
 * Delivery is durable and at least once through the workflow transition
 * outbox. Listeners handle notifications, external integrations, and
 * cross-process coordination, and must make side effects idempotent.
 *
 * Use `$payload` for a stable, flattened contract — avoids coupling
 * listeners to Eloquent model internals or transition object structure.
 */
class TransitionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $flow,
        public readonly Model $model,
        public readonly StatusTransition $transition,
        public readonly StatusHistory $history,
        public readonly TransitionContext $context,
        public readonly TransitionPayload $payload,
    ) {}
}
