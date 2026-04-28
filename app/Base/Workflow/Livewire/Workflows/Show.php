<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Livewire\Workflows;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Show extends Component
{
    use SavesValidatedFields;
    use TogglesSort;

    public Workflow $workflow;

    public string $statusesSortBy = 'position';

    public string $statusesSortDir = 'asc';

    public string $transitionsSortBy = 'from_code';

    public string $transitionsSortDir = 'asc';

    public string $kanbanColumnsSortBy = 'position';

    public string $kanbanColumnsSortDir = 'asc';

    private const STATUS_SORTABLE = [
        'position' => true,
        'code' => true,
        'label' => true,
        'kanban_code' => true,
        'pic' => true,
        'notify' => true,
        'is_active' => true,
    ];

    private const TRANSITION_SORTABLE = [
        'from_code' => true,
        'to_code' => true,
        'label' => true,
        'capability' => true,
        'guard' => true,
        'action' => true,
        'sla_seconds' => true,
        'is_active' => true,
    ];

    private const KANBAN_SORTABLE = [
        'position' => true,
        'code' => true,
        'label' => true,
        'wip_limit' => true,
    ];

    public function mount(Workflow $workflow): void
    {
        $this->workflow = $workflow;
    }

    public function sortStatuses(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::STATUS_SORTABLE,
            defaultDir: [
                'position' => 'asc',
                'code' => 'asc',
                'label' => 'asc',
                'kanban_code' => 'asc',
                'pic' => 'asc',
                'notify' => 'asc',
                'is_active' => 'desc',
            ],
            sortByProperty: 'statusesSortBy',
            sortDirProperty: 'statusesSortDir',
            resetPage: false,
        );
    }

    public function sortTransitions(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::TRANSITION_SORTABLE,
            defaultDir: [
                'from_code' => 'asc',
                'to_code' => 'asc',
                'label' => 'asc',
                'capability' => 'asc',
                'guard' => 'asc',
                'action' => 'asc',
                'sla_seconds' => 'asc',
                'is_active' => 'desc',
            ],
            sortByProperty: 'transitionsSortBy',
            sortDirProperty: 'transitionsSortDir',
            resetPage: false,
        );
    }

    public function sortKanbanColumns(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::KANBAN_SORTABLE,
            defaultDir: [
                'position' => 'asc',
                'code' => 'asc',
                'label' => 'asc',
                'wip_limit' => 'asc',
            ],
            sortByProperty: 'kanbanColumnsSortBy',
            sortDirProperty: 'kanbanColumnsSortDir',
            resetPage: false,
        );
    }

    public function render(): View
    {
        $flow = $this->workflow->code;

        $statuses = $this->sortStatusConfigs(
            StatusConfig::query()->forFlow($flow)->get(),
        );

        $transitions = $this->sortTransitionsCollection(
            StatusTransition::query()->forFlow($flow)->get(),
        );

        $kanbanColumns = $this->sortKanbanColumnsCollection(
            KanbanColumn::query()->forFlow($flow)->get(),
        );

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

    /**
     * @param  Collection<int, StatusConfig>  $statuses
     * @return Collection<int, StatusConfig>
     */
    private function sortStatusConfigs(Collection $statuses): Collection
    {
        $dir = $this->statusesSortDir === 'desc' ? -1 : 1;

        return $statuses
            ->sort(function (StatusConfig $a, StatusConfig $b) use ($dir): int {
                $picKey = function (StatusConfig $s): string {
                    $p = $s->pic;
                    $p = is_array($p) ? $p : [];
                    sort($p);

                    return implode(',', $p);
                };

                $notifyKey = function (StatusConfig $s): string {
                    $n = $s->notifications;
                    $on = is_array($n) ? ($n['on_enter'] ?? []) : [];
                    $on = is_array($on) ? $on : [];
                    sort($on);

                    return implode(',', $on);
                };

                $primary = match ($this->statusesSortBy) {
                    'position' => $dir * (($a->position ?? 0) <=> ($b->position ?? 0)),
                    'code' => $dir * strcmp((string) $a->code, (string) $b->code),
                    'label' => $dir * strcmp((string) $a->label, (string) $b->label),
                    'kanban_code' => $dir * strcmp((string) ($a->kanban_code ?? ''), (string) ($b->kanban_code ?? '')),
                    'pic' => $dir * strcmp($picKey($a), $picKey($b)),
                    'notify' => $dir * strcmp($notifyKey($a), $notifyKey($b)),
                    'is_active' => $dir * (((int) (bool) $a->is_active) <=> ((int) (bool) $b->is_active)),
                    default => $dir * (($a->position ?? 0) <=> ($b->position ?? 0)),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }

    /**
     * @param  Collection<int, StatusTransition>  $transitions
     * @return Collection<int, StatusTransition>
     */
    private function sortTransitionsCollection(Collection $transitions): Collection
    {
        $dir = $this->transitionsSortDir === 'desc' ? -1 : 1;

        return $transitions
            ->sort(function (StatusTransition $a, StatusTransition $b) use ($dir): int {
                $primary = match ($this->transitionsSortBy) {
                    'from_code' => $dir * strcmp((string) $a->from_code, (string) $b->from_code),
                    'to_code' => $dir * strcmp((string) $a->to_code, (string) $b->to_code),
                    'label' => $dir * strcmp((string) ($a->label ?? ''), (string) ($b->label ?? '')),
                    'capability' => $dir * strcmp((string) ($a->capability ?? ''), (string) ($b->capability ?? '')),
                    'guard' => $dir * strcmp(
                        (string) ($a->guard_class ? class_basename($a->guard_class) : ''),
                        (string) ($b->guard_class ? class_basename($b->guard_class) : ''),
                    ),
                    'action' => $dir * strcmp(
                        (string) ($a->action_class ? class_basename($a->action_class) : ''),
                        (string) ($b->action_class ? class_basename($b->action_class) : ''),
                    ),
                    'sla_seconds' => $dir * (((int) ($a->sla_seconds ?? 0)) <=> ((int) ($b->sla_seconds ?? 0))),
                    'is_active' => $dir * (((int) (bool) $a->is_active) <=> ((int) (bool) $b->is_active)),
                    default => $dir * strcmp((string) $a->from_code, (string) $b->from_code),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                $tie = ($a->position ?? 0) <=> ($b->position ?? 0);

                if ($tie !== 0) {
                    return $tie;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }

    /**
     * @param  Collection<int, KanbanColumn>  $columns
     * @return Collection<int, KanbanColumn>
     */
    private function sortKanbanColumnsCollection(Collection $columns): Collection
    {
        $dir = $this->kanbanColumnsSortDir === 'desc' ? -1 : 1;

        return $columns
            ->sort(function (KanbanColumn $a, KanbanColumn $b) use ($dir): int {
                $primary = match ($this->kanbanColumnsSortBy) {
                    'position' => $dir * (($a->position ?? 0) <=> ($b->position ?? 0)),
                    'code' => $dir * strcmp((string) $a->code, (string) $b->code),
                    'label' => $dir * strcmp((string) $a->label, (string) $b->label),
                    'wip_limit' => $dir * (((int) ($a->wip_limit ?? 0)) <=> ((int) ($b->wip_limit ?? 0))),
                    default => $dir * (($a->position ?? 0) <=> ($b->position ?? 0)),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }
}
