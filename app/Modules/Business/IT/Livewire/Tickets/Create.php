<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Livewire\Tickets;

use App\Base\Authz\DTO\Actor;
use App\Modules\Business\IT\Services\TicketService;
use Illuminate\Contracts\View\View;
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

    public function store(TicketService $ticketService): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();
        $reporter = $user->employee;

        if (! $reporter) {
            Session::flash('error', __('Your account must be linked to an employee record.'));

            return;
        }

        $actor = Actor::forUser($user);

        $ticket = $ticketService->create($actor, $reporter, $validated);

        Session::flash('success', __('Ticket created successfully.'));

        $this->redirect(route('it.tickets.show', $ticket), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.it.tickets.create');
    }
}
