<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Livewire\Tickets;

use App\Base\Authz\DTO\Actor;
use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Business\IT\Services\TicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Show extends Component
{
    public Ticket $ticket;

    public string $transitionComment = '';

    public function mount(Ticket $ticket): void
    {
        $this->ticket = $ticket->load('reporter', 'assignee');
    }

    /**
     * Transition the ticket to a new status via the workflow engine.
     */
    public function transitionTo(string $toCode, TicketService $ticketService): void
    {
        $user = Auth::user();
        $actor = Actor::forUser($user);

        $result = $ticketService->transition(
            $this->ticket,
            $actor,
            $toCode,
            $this->transitionComment ?: null,
        );

        if ($result->success) {
            $this->transitionComment = '';
            $this->ticket->refresh();
            Session::flash('success', __('Ticket transitioned successfully.'));
        } else {
            Session::flash('error', $result->reason ?? __('Transition failed.'));
        }
    }

    public function priorityVariant(string $priority): string
    {
        return match ($priority) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'default',
            default => 'default',
        };
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'open' => 'info',
            'assigned' => 'accent',
            'in_progress' => 'warning',
            'blocked' => 'danger',
            'awaiting_parts' => 'warning',
            'review' => 'accent',
            'resolved' => 'success',
            'closed' => 'default',
            default => 'default',
        };
    }

    public function render(): View
    {
        return view('livewire.it.tickets.show', [
            'timeline' => $this->ticket->statusTimeline(),
            'availableTransitions' => $this->ticket->availableTransitions(),
        ]);
    }
}
