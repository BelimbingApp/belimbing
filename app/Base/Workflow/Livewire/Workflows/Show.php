<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Livewire\Workflows;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    use SavesValidatedFields;

    public Workflow $workflow;

    public function mount(Workflow $workflow): void
    {
        $this->workflow = $workflow;
    }

    public function render(): View
    {
        $flow = $this->workflow->code;

        $statuses = StatusConfig::query()
            ->forFlow($flow)
            ->orderBy('position')
            ->get();

        $transitions = StatusTransition::query()
            ->forFlow($flow)
            ->orderBy('from_code')
            ->orderBy('position')
            ->get();

        $kanbanColumns = KanbanColumn::query()
            ->forFlow($flow)
            ->orderBy('position')
            ->get();

        return view('livewire.admin.workflows.show', [
            'statuses' => $statuses,
            'transitions' => $transitions,
            'kanbanColumns' => $kanbanColumns,
        ]);
    }

    /**
     * Save a single field on a StatusConfig row.
     *
     * @param  int  $statusId  The StatusConfig ID
     * @param  string  $field  The field name to update
     * @param  mixed  $value  The new value
     */
    public function saveStatusField(int $statusId, string $field, mixed $value): void
    {
        $rules = [
            'pic' => ['nullable', 'array'],
            'pic.*' => ['string', 'max:100'],
            'notifications' => ['nullable', 'array'],
        ];

        $status = StatusConfig::query()
            ->where('flow', $this->workflow->code)
            ->findOrFail($statusId);

        $this->saveValidatedField($status, $field, $value, $rules);
    }

    /**
     * Format SLA seconds into a human-readable string.
     */
    public function formatSla(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        if ($seconds >= 86400) {
            return round($seconds / 86400, 1).'d';
        }

        if ($seconds >= 3600) {
            return round($seconds / 3600, 1).'h';
        }

        return $seconds.'s';
    }
}
