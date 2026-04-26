<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Services\TicketService;

/**
 * Ticket interaction tool for coding agents.
 *
 * Lets an agent post comments to a ticket's status history (progress
 * updates, questions, deliverables, errors) and transition the ticket
 * to a new status. All mutations flow through TicketService.
 *
 * Gated by `ai.tool_ticket_update.execute` authz capability.
 */
class TicketUpdateTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const VALID_ACTIONS = ['post_comment', 'transition'];

    private const VALID_COMMENT_TAGS = [
        'agent_progress',
        'agent_question',
        'agent_deliverable',
        'agent_error',
    ];

    private const MAX_COMMENT_LENGTH = 5000;

    public function __construct(
        private readonly TicketService $ticketService,
        private readonly AgentExecutionContext $executionContext,
    ) {}

    public function name(): string
    {
        return 'ticket_update';
    }

    public function description(): string
    {
        return 'Post a comment or transition an IT ticket. '
            .'Use action "post_comment" with a comment_tag (agent_progress, agent_question, '
            .'agent_deliverable, agent_error) to add a timeline entry. '
            .'Use action "transition" with to_status to change the ticket status '
            .'(e.g., in_progress, blocked, review, resolved). '
            .'Always provide the ticket_id.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->integer(
                'ticket_id',
                'The ID of the IT ticket to update.'
            )->required()
            ->string(
                'action',
                'The action to perform: "post_comment" to add a timeline entry, '
                    .'"transition" to change the ticket status.',
                enum: self::VALID_ACTIONS,
            )->required()
            ->string(
                'comment',
                'The comment text. Required for post_comment, optional for transition.'
            )
            ->string(
                'comment_tag',
                'Tag categorizing the comment: agent_progress, agent_question, '
                    .'agent_deliverable, or agent_error.',
                enum: self::VALID_COMMENT_TAGS,
            )
            ->string(
                'to_status',
                'Target status code for transition action '
                    .'(e.g., in_progress, blocked, review, resolved).'
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::AUTOMATION;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::INTERNAL;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_ticket_update.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Ticket Update',
            'summary' => 'Post comments and transition IT tickets.',
            'explanation' => 'Allows an agent to interact with IT tickets by posting progress updates, '
                .'questions, deliverables, and error reports to the ticket timeline. '
                .'Also supports transitioning the ticket through its workflow statuses.',
            'testExamples' => [
                [
                    'label' => 'Post progress update',
                    'input' => [
                        'ticket_id' => 1,
                        'action' => 'post_comment',
                        'comment' => 'Migration and model created successfully.',
                        'comment_tag' => 'agent_progress',
                    ],
                ],
                [
                    'label' => 'Transition to blocked',
                    'input' => [
                        'ticket_id' => 1,
                        'action' => 'transition',
                        'to_status' => 'blocked',
                        'comment' => 'Need clarification on table structure.',
                        'comment_tag' => 'agent_question',
                    ],
                    'runnable' => false,
                ],
            ],
            'limits' => [
                'Comment length limited to '.self::MAX_COMMENT_LENGTH.' characters',
                'Agent must have ticket access capability',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $ticketId = $this->requireInt($arguments, 'ticket_id', min: 1);
        $action = $this->requireEnum($arguments, 'action', self::VALID_ACTIONS);

        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null) {
            return ToolResult::error(
                "Ticket #{$ticketId} not found.",
                'ticket_not_found',
            );
        }

        $actor = $this->resolveAgentActor($ticket);

        return match ($action) {
            'post_comment' => $this->handlePostComment($ticket, $actor, $arguments),
            'transition' => $this->handleTransition($ticket, $actor, $arguments),
        };
    }

    /**
     * Post a comment to the ticket timeline.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function handlePostComment(Ticket $ticket, Actor $actor, array $arguments): ToolResult
    {
        $comment = $this->requireString($arguments, 'comment');
        $commentTag = $this->optionalString($arguments, 'comment_tag');

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new ToolArgumentException(
                'Comment exceeds maximum length of '.self::MAX_COMMENT_LENGTH.' characters.'
            );
        }

        if ($commentTag !== null && ! in_array($commentTag, self::VALID_COMMENT_TAGS, true)) {
            throw new ToolArgumentException(
                'Invalid comment_tag. Must be one of: '.implode(', ', self::VALID_COMMENT_TAGS).'.'
            );
        }

        $this->ticketService->postComment($ticket, $actor, $comment, $commentTag);

        return ToolResult::success(
            "Comment posted to ticket #{$ticket->id}."
            .($commentTag !== null ? " Tag: {$commentTag}." : '')
        );
    }

    /**
     * Transition the ticket to a new status.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function handleTransition(Ticket $ticket, Actor $actor, array $arguments): ToolResult
    {
        $toStatus = $this->requireString($arguments, 'to_status', 'target status');
        $comment = $this->optionalString($arguments, 'comment');
        $commentTag = $this->optionalString($arguments, 'comment_tag');

        $result = $this->ticketService->transition(
            $ticket,
            $actor,
            $toStatus,
            $comment,
            $commentTag,
        );

        if ($result->success) {
            return ToolResult::success(
                "Ticket #{$ticket->id} transitioned to '{$toStatus}'."
                .($comment !== null ? " Comment: {$comment}" : '')
            );
        }

        return ToolResult::error(
            $result->reason ?? 'Transition failed.',
            'transition_failed',
        );
    }

    /**
     * Build an Actor DTO for the current agent context.
     *
     * Prefers the AgentExecutionContext (set during queued job execution),
     * then the authenticated user's linked employee, before falling back
     * to Lara's provisioned employee record.
     */
    private function resolveAgentActor(Ticket $ticket): Actor
    {
        if ($this->executionContext->active()) {
            $employee = Employee::query()->find($this->executionContext->employeeId());

            if ($employee !== null) {
                return $this->makeAgentActor(
                    employeeId: $employee->id,
                    companyId: $employee->company_id,
                    actingForUserId: $this->executionContext->actingForUserId(),
                );
            }
        }

        $user = auth()->user();

        if ($user?->employee !== null) {
            return $this->makeAgentActor(
                employeeId: $user->employee->id,
                companyId: $user->employee->company_id,
                actingForUserId: (int) $user->getAuthIdentifier(),
            );
        }

        $lara = Employee::query()->find(Employee::LARA_ID);

        return $this->makeAgentActor(
            employeeId: $lara?->id ?? Employee::LARA_ID,
            companyId: $lara?->company_id ?? $ticket->company_id,
            actingForUserId: $user !== null ? (int) $user->getAuthIdentifier() : null,
        );
    }

    private function makeAgentActor(int $employeeId, ?int $companyId, ?int $actingForUserId): Actor
    {
        return new Actor(
            type: PrincipalType::AGENT,
            id: $employeeId,
            companyId: $companyId,
            actingForUserId: $actingForUserId,
        );
    }
}
