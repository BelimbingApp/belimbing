<?php

namespace App\Modules\People\Attendance\Console\Commands;

use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:attendance:roster')]
class RosterCommand extends Command
{
    protected $signature = 'blb:attendance:roster
        {action : draft|validate|explain|publish-dry-run}
        {--company= : Company id}
        {--from= : Start date}
        {--to= : End date}';

    protected $description = 'Emit stable JSON for roster draft, validation, explanation, and publish dry-run operator workflows.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['draft', 'validate', 'explain', 'publish-dry-run'], true)) {
            $this->error('Unsupported roster action.');

            return self::FAILURE;
        }

        $query = AttendanceRosterAssignment::query()->with(['employee', 'shiftTemplate', 'policyGroup']);

        if (filter_var($this->option('company'), FILTER_VALIDATE_INT) !== false) {
            $query->where('company_id', (int) $this->option('company'));
        }

        $from = (string) ($this->option('from') ?? '');
        $to = (string) ($this->option('to') ?? '');
        if ($from !== '') {
            $query->where(function ($scope) use ($from): void {
                $scope->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from);
            });
        }

        if ($to !== '') {
            $query->whereDate('effective_from', '<=', $to);
        }

        $assignments = $query->orderBy('effective_from')->limit(500)->get();
        $drafts = $assignments->where('publish_state', 'draft');
        $payload = [
            'action' => $action,
            'status' => 'ok',
            'summary' => [
                'assignments' => $assignments->count(),
                'drafts' => $drafts->count(),
                'published' => $assignments->where('publish_state', 'published')->count(),
            ],
            'findings' => $action === 'validate'
                ? $this->validationFindings($assignments)
                : [],
            'publish_preview' => $action === 'publish-dry-run'
                ? $drafts->map(fn (AttendanceRosterAssignment $assignment): array => [
                    'assignment_id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'employee_number' => $assignment->employee?->employee_number,
                    'effective_from' => $assignment->effective_from?->toDateString(),
                    'effective_to' => $assignment->effective_to?->toDateString(),
                    'shift' => $assignment->shiftTemplate?->code,
                    'policy' => $assignment->policyGroup?->code,
                ])->values()->all()
                : [],
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, AttendanceRosterAssignment>  $assignments
     * @return list<array<string, string>>
     */
    private function validationFindings($assignments): array
    {
        $findings = [];

        foreach ($assignments->groupBy('employee_id') as $employeeId => $employeeAssignments) {
            $sorted = $employeeAssignments->sortBy('effective_from')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];
                $previousEnd = $previous->effective_to?->toDateString() ?? '9999-12-31';

                if ($previousEnd >= $current->effective_from?->toDateString()) {
                    $findings[] = [
                        'severity' => 'warning',
                        'code' => 'overlap_existing_roster',
                        'message' => "Employee {$employeeId} has overlapping roster assignments.",
                    ];
                }
            }
        }

        return $findings;
    }
}
