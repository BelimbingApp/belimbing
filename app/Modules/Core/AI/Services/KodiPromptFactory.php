<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Core\AI\DTO\PromptPackage;
use App\Modules\Core\AI\DTO\PromptSection;
use App\Modules\Core\AI\Enums\PromptSectionType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * System prompt factory for Kodi, BLB's developer agent.
 *
 * Builds a context-rich system prompt through the workspace-driven
 * prompt pipeline. Kodi-specific context (ticket, dispatch metadata)
 * is contributed as operational sections.
 */
class KodiPromptFactory
{
    /**
     * Maximum number of recent timeline entries to include in context.
     */
    private const MAX_TIMELINE_ENTRIES = 10;

    public function __construct(
        private readonly WorkspaceResolver $workspaceResolver,
        private readonly WorkspaceValidator $workspaceValidator,
        private readonly PromptPackageFactory $packageFactory,
        private readonly PromptRenderer $renderer,
    ) {}

    /**
     * Build the system prompt for a dispatched agent task.
     *
     * @param  OperationDispatch  $dispatch  The dispatch record
     * @param  Model|null  $entity  Associated domain entity (ticket, QAC case, etc.)
     */
    public function buildForDispatch(OperationDispatch $dispatch, ?Model $entity = null): string
    {
        $package = $this->buildPackage($dispatch, $entity);

        return $this->renderer->render($package);
    }

    /**
     * Build the full prompt package for diagnostics or metadata attachment.
     */
    public function buildPackage(OperationDispatch $dispatch, ?Model $entity = null): PromptPackage
    {
        $manifest = $this->workspaceResolver->resolve(Employee::KODI_ID);
        $validation = $this->workspaceValidator->validate($manifest);

        if (! $validation->valid) {
            throw new BlbConfigurationException(
                'Kodi workspace validation failed: '.implode('; ', $validation->errors),
                BlbErrorCode::WORKSPACE_VALIDATION_FAILED,
                ['errors' => $validation->errors],
            );
        }

        return $this->packageFactory->build(
            manifest: $manifest,
            validation: $validation,
            operationalSections: $this->operationalSections($dispatch, $entity),
        );
    }

    /**
     * Build Kodi-specific operational context sections.
     *
     * @return list<PromptSection>
     */
    private function operationalSections(OperationDispatch $dispatch, ?Model $entity): array
    {
        $sections = [];

        if ($entity instanceof Ticket) {
            $sections[] = $this->ticketSection($entity);
        }

        $sections[] = $this->dispatchSection($dispatch);

        return $sections;
    }

    /**
     * Build the ticket context section with recent timeline.
     */
    private function ticketSection(Ticket $ticket): PromptSection
    {
        $context = [
            'ticket_id' => $ticket->id,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'category' => $ticket->category,
            'description' => $ticket->description,
            'reporter' => $ticket->reporter?->displayName(),
            'assignee' => $ticket->assignee?->displayName(),
        ];

        $timeline = $this->recentTimeline($ticket);

        if ($timeline !== []) {
            $context['recent_timeline'] = $timeline;
        }

        $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new PromptSection(
            label: 'ticket_context',
            content: "Ticket context (JSON):\n".$encoded,
            type: PromptSectionType::Operational,
            order: 0,
            source: 'kodi_ticket_context',
        );
    }

    /**
     * Build the dispatch metadata section.
     */
    private function dispatchSection(OperationDispatch $dispatch): PromptSection
    {
        $context = [
            'dispatch_id' => $dispatch->id,
            'task' => $dispatch->task,
            'employee_id' => $dispatch->employee_id,
            'acting_for_user_id' => $dispatch->acting_for_user_id,
        ];

        $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new PromptSection(
            label: 'dispatch_context',
            content: "Dispatch context (JSON):\n".$encoded,
            type: PromptSectionType::Operational,
            order: 1,
            source: 'kodi_dispatch_context',
        );
    }

    /**
     * Fetch recent timeline entries for the ticket.
     *
     * @return list<array{status: string, comment: string|null, comment_tag: string|null, actor_id: int, transitioned_at: string}>
     */
    private function recentTimeline(Ticket $ticket): array
    {
        return StatusHistory::query()
            ->where('flow', 'it_ticket')
            ->where('flow_id', $ticket->id)
            ->orderByDesc('transitioned_at')
            ->limit(self::MAX_TIMELINE_ENTRIES)
            ->get()
            ->map(fn (StatusHistory $entry): array => [
                'status' => $entry->status,
                'comment' => $entry->comment,
                'comment_tag' => $entry->comment_tag,
                'actor_id' => $entry->actor_id,
                'transitioned_at' => $entry->transitioned_at?->toIso8601String() ?? '',
            ])
            ->reverse()
            ->values()
            ->all();
    }
}
