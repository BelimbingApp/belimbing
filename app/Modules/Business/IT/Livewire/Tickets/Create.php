<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Livewire\Tickets;

use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Business\IT\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $title = '';

    public string $priority = 'medium';

    public ?string $category = null;

    public ?string $description = null;

    public ?string $location = null;

    public function store(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();

        $ticket = Ticket::query()->create([
            'company_id' => $user->company_id,
            'reporter_id' => $user->id,
            'status' => 'open',
            'title' => $validated['title'],
            'priority' => $validated['priority'],
            'category' => $validated['category'],
            'description' => $validated['description'],
            'location' => $validated['location'],
        ]);

        // Record initial status in workflow history
        StatusHistory::query()->create([
            'flow' => 'it_ticket',
            'flow_id' => $ticket->id,
            'status' => 'open',
            'actor_id' => $user->id,
            'comment' => $validated['description'],
            'comment_tag' => 'report',
            'metadata' => ['priority' => $validated['priority']],
            'transitioned_at' => Carbon::now(),
        ]);

        Session::flash('success', __('Ticket created successfully.'));

        $this->redirect(route('it.tickets.show', $ticket), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.it.tickets.create');
    }
}
