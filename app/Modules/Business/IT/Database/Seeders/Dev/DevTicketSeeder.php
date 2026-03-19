<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Database\Seeders\Dev;

use App\Base\Authz\DTO\Actor;
use App\Base\Database\Seeders\DevSeeder;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Services\WorkflowEngine;
use App\Modules\Business\IT\Database\Seeders\TicketWorkflowSeeder;
use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Database\Seeders\Dev\DevUserSeeder;
use App\Modules\Core\User\Models\User;

class DevTicketSeeder extends DevSeeder
{
    private const FLOW = 'it_ticket';

    protected array $dependencies = [
        DevUserSeeder::class,
    ];

    /**
     * Seed sample IT tickets with realistic status distributions and history.
     *
     * Runs the workflow seeder first to ensure statuses/transitions exist,
     * then creates tickets at various lifecycle stages.
     */
    protected function seed(): void
    {
        (new TicketWorkflowSeeder)->run();

        $company = Company::query()->where('id', Company::LICENSEE_ID)->first();

        if (! $company) {
            return;
        }

        $users = User::query()
            ->where('company_id', $company->id)
            ->limit(3)
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $engine = app(WorkflowEngine::class);
        $reporter = $users->first();
        $assignee = $users->count() > 1 ? $users->get(1) : $reporter;

        $this->seedOpenTickets($company, $reporter);
        $this->seedInProgressTickets($company, $reporter, $assignee, $engine);
        $this->seedResolvedTickets($company, $reporter, $assignee, $engine);
        $this->seedClosedTickets($company, $reporter, $assignee, $engine);
    }

    /**
     * Create tickets that remain in the 'open' status.
     */
    private function seedOpenTickets(Company $company, User $reporter): void
    {
        $tickets = [
            ['title' => 'Projector in Meeting Room 3A not displaying', 'priority' => 'medium', 'category' => 'hardware', 'location' => 'Floor 3 - Meeting Room 3A'],
            ['title' => 'VPN connection drops intermittently', 'priority' => 'high', 'category' => 'network'],
            ['title' => 'Need access to shared drive \\\\files\\marketing', 'priority' => 'low', 'category' => 'access'],
        ];

        foreach ($tickets as $data) {
            Ticket::query()->firstOrCreate(
                ['title' => $data['title'], 'company_id' => $company->id],
                array_merge($data, [
                    'company_id' => $company->id,
                    'reporter_id' => $reporter->id,
                    'status' => 'open',
                ]),
            );
        }
    }

    /**
     * Create tickets progressed to 'in_progress' via the workflow engine.
     */
    private function seedInProgressTickets(Company $company, User $reporter, User $assignee, WorkflowEngine $engine): void
    {
        $tickets = [
            ['title' => 'Printer on Floor 2 paper jam recurring', 'priority' => 'medium', 'category' => 'hardware', 'location' => 'Floor 2 - Open Office'],
            ['title' => 'Outlook calendar sync broken after update', 'priority' => 'high', 'category' => 'software'],
        ];

        foreach ($tickets as $data) {
            $ticket = Ticket::query()->firstOrCreate(
                ['title' => $data['title'], 'company_id' => $company->id],
                array_merge($data, [
                    'company_id' => $company->id,
                    'reporter_id' => $reporter->id,
                    'status' => 'open',
                ]),
            );

            if ($ticket->status === 'open') {
                $this->advanceTicket($ticket, $assignee, $engine, ['assigned', 'in_progress']);
            }
        }
    }

    /**
     * Create tickets that have reached 'resolved'.
     */
    private function seedResolvedTickets(Company $company, User $reporter, User $assignee, WorkflowEngine $engine): void
    {
        $ticket = Ticket::query()->firstOrCreate(
            ['title' => 'Wi-Fi dead zone near server room entrance', 'company_id' => $company->id],
            [
                'company_id' => $company->id,
                'reporter_id' => $reporter->id,
                'status' => 'open',
                'priority' => 'medium',
                'category' => 'network',
                'location' => 'Floor 3 - Server Room',
            ],
        );

        if ($ticket->status === 'open') {
            $this->advanceTicket($ticket, $assignee, $engine, ['assigned', 'in_progress', 'resolved']);
        }
    }

    /**
     * Create tickets that have completed the full lifecycle to 'closed'.
     */
    private function seedClosedTickets(Company $company, User $reporter, User $assignee, WorkflowEngine $engine): void
    {
        $tickets = [
            ['title' => 'New employee laptop setup — Tan Siew Mei', 'priority' => 'medium', 'category' => 'hardware'],
            ['title' => 'Email password reset for reception account', 'priority' => 'low', 'category' => 'access'],
        ];

        foreach ($tickets as $data) {
            $ticket = Ticket::query()->firstOrCreate(
                ['title' => $data['title'], 'company_id' => $company->id],
                array_merge($data, [
                    'company_id' => $company->id,
                    'reporter_id' => $reporter->id,
                    'status' => 'open',
                ]),
            );

            if ($ticket->status === 'open') {
                $this->advanceTicket($ticket, $assignee, $engine, ['assigned', 'in_progress', 'resolved', 'closed']);
            }
        }
    }

    /**
     * Advance a ticket through a sequence of statuses using the workflow engine.
     *
     * @param  array<int, string>  $statuses
     */
    private function advanceTicket(Ticket $ticket, User $actor, WorkflowEngine $engine, array $statuses): void
    {
        $context = new TransitionContext(
            actor: Actor::forUser($actor),
        );

        foreach ($statuses as $status) {
            $result = $engine->transition($ticket, self::FLOW, $status, $context);

            if (! $result->success) {
                break;
            }

            $ticket->refresh();
        }
    }
}
