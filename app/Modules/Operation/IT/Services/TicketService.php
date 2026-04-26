<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Operation\IT\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Operation\IT\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Domain service for IT ticket operations.
 *
 * Centralizes ticket creation and comment posting so that both the
 * Livewire UI and AI tools share the same mutation logic.
 */
class TicketService
{
    /**
     * Create a new IT ticket with initial status history.
     *
     * @param  Actor  $actor  The principal performing the action (audit/auth context)
     * @param  Employee  $reporter  The employee reporting the ticket (business ownership)
     * @param  array{title: string, priority: string, category?: string|null, description?: string|null, location?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function create(Actor $actor, Employee $reporter, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $reporter, $data): Ticket {
            $ticket = Ticket::query()->create([
                'company_id' => $reporter->company_id,
                'reporter_id' => $reporter->id,
                'status' => 'open',
                'title' => $data['title'],
                'priority' => $data['priority'],
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            StatusHistory::query()->create([
                'flow' => 'it_ticket',
                'flow_id' => $ticket->id,
                'status' => 'open',
                'actor_id' => $actor->id,
                'comment' => $data['description'] ?? null,
                'comment_tag' => 'report',
                'metadata' => ['priority' => $data['priority']],
                'transitioned_at' => Carbon::now(),
            ]);

            return $ticket;
        });
    }

    /**
     * Post a comment to a ticket's status history without changing status.
     *
     * @param  Ticket  $ticket  The ticket to comment on
     * @param  Actor  $actor  The principal posting the comment
     * @param  string  $comment  The comment text
     * @param  string|null  $commentTag  Comment category (e.g., agent_progress, agent_question)
     * @param  array<string, mixed>|null  $metadata  Additional context
     */
    public function postComment(
        Ticket $ticket,
        Actor $actor,
        string $comment,
        ?string $commentTag = null,
        ?array $metadata = null,
    ): StatusHistory {
        return StatusHistory::query()->create([
            'flow' => 'it_ticket',
            'flow_id' => $ticket->id,
            'status' => $ticket->status,
            'actor_id' => $actor->id,
            'comment' => $comment,
            'comment_tag' => $commentTag,
            'metadata' => $metadata,
            'transitioned_at' => Carbon::now(),
        ]);
    }

    /**
     * Transition a ticket to a new status via the workflow engine.
     *
     * @param  Ticket  $ticket  The ticket to transition
     * @param  Actor  $actor  The principal triggering the transition
     * @param  string  $toCode  Target status code
     * @param  string|null  $comment  Optional transition comment
     * @param  string|null  $commentTag  Comment category
     */
    public function transition(
        Ticket $ticket,
        Actor $actor,
        string $toCode,
        ?string $comment = null,
        ?string $commentTag = null,
    ): TransitionResult {
        $context = new TransitionContext(
            actor: $actor,
            comment: $comment,
            commentTag: $commentTag,
        );

        return $ticket->transitionTo($toCode, $context);
    }
}
