<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Core\AI\Models\AgentTaskDispatch;
use Illuminate\Database\Eloquent\Model;

/**
 * System prompt factory for Kodi, BLB's developer agent.
 *
 * Builds a context-rich system prompt that includes Kodi's identity,
 * coding conventions, ticket context, and dispatch metadata. Used by
 * RunAgentTaskJob when executing agent tasks from the queue.
 */
class KodiPromptFactory
{
    /**
     * Maximum number of recent timeline entries to include in context.
     */
    private const MAX_TIMELINE_ENTRIES = 10;

    /**
     * Build the system prompt for a dispatched agent task.
     *
     * @param  AgentTaskDispatch  $dispatch  The dispatch record
     * @param  Model|null  $entity  Associated domain entity (ticket, QAC case, etc.)
     */
    public function buildForDispatch(AgentTaskDispatch $dispatch, ?Model $entity = null): string
    {
        $sections = [$this->basePrompt()];

        if ($entity instanceof Ticket) {
            $sections[] = $this->ticketContextSection($entity);
        }

        $sections[] = $this->dispatchContextSection($dispatch);

        return implode("\n\n", $sections);
    }

    /**
     * Load the base Kodi system prompt from the resource file.
     */
    private function basePrompt(): string
    {
        $path = app_path('Modules/Core/AI/Resources/kodi/system_prompt.md');

        if (! is_file($path)) {
            throw new BlbConfigurationException(
                'Kodi base prompt file missing: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_MISSING,
                ['path' => $path, 'resource' => 'kodi']
            );
        }

        $content = file_get_contents($path);

        if (! is_string($content)) {
            throw new BlbConfigurationException(
                'Failed to read Kodi base prompt file: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_UNREADABLE,
                ['path' => $path, 'resource' => 'kodi']
            );
        }

        return trim($content);
    }

    /**
     * Build the ticket context section with recent timeline.
     */
    private function ticketContextSection(Ticket $ticket): string
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

        return "Ticket context (JSON):\n".$encoded;
    }

    /**
     * Build the dispatch metadata section.
     */
    private function dispatchContextSection(AgentTaskDispatch $dispatch): string
    {
        $context = [
            'dispatch_id' => $dispatch->id,
            'task' => $dispatch->task,
            'employee_id' => $dispatch->employee_id,
            'acting_for_user_id' => $dispatch->acting_for_user_id,
        ];

        $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "Dispatch context (JSON):\n".$encoded;
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
