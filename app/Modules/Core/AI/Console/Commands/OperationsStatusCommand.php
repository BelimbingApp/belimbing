<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Display operation status from the dispatch ledger.
 *
 * Can show a single operation by ID, or a summary dashboard of
 * recent operations with counts by status. Useful for operator
 * diagnostics and monitoring.
 */
#[AsCommand(name: 'blb:ai:operations:status')]
class OperationsStatusCommand extends Command
{
    protected $description = 'Show AI operation status — by ID or summary dashboard';

    protected $signature = 'blb:ai:operations:status
        {operation? : Operation dispatch ID (e.g., op_xxx). Omit for summary.}
        {--type= : Filter by type: agent_task, scheduled_task, background_command}
        {--status= : Filter by status: queued, running, succeeded, failed, cancelled}
        {--limit=15 : Maximum operations to show in listing}';

    public function handle(OperationsDispatchService $service): int
    {
        $operationId = $this->argument('operation');

        if (is_string($operationId) && $operationId !== '') {
            return $this->showSingleOperation($service, $operationId);
        }

        return $this->showDashboard($service);
    }

    /**
     * Display details for a single operation.
     */
    private function showSingleOperation(OperationsDispatchService $service, string $operationId): int
    {
        $dispatch = $service->find($operationId);

        if ($dispatch === null) {
            $this->components->error("Operation \"{$operationId}\" not found.");

            return self::FAILURE;
        }

        $this->components->info("Operation: {$dispatch->id}");

        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $dispatch->id],
                ['Type', $dispatch->operation_type->label()],
                ['Status', $dispatch->status->label()],
                ['Task', $this->truncateForDisplay($dispatch->task, 80)],
                ['Employee ID', (string) ($dispatch->employee_id ?? '-')],
                ['Acting User', (string) ($dispatch->acting_for_user_id ?? '-')],
                ['Entity', $dispatch->entity_type ? "{$dispatch->entity_type}#{$dispatch->entity_id}" : '-'],
                ['Created', $dispatch->created_at?->toDateTimeString() ?? '-'],
                ['Started', $dispatch->started_at?->toDateTimeString() ?? '-'],
                ['Finished', $dispatch->finished_at?->toDateTimeString() ?? '-'],
                ['Run ID', $dispatch->run_id ?? '-'],
            ],
        );

        if ($dispatch->status === OperationStatus::Succeeded && $dispatch->result_summary !== null) {
            $this->newLine();
            $this->components->info('Result summary:');
            $this->line($this->truncateForDisplay($dispatch->result_summary, 500));
        }

        if ($dispatch->status === OperationStatus::Failed && $dispatch->error_message !== null) {
            $this->newLine();
            $this->components->error('Error:');
            $this->line($this->truncateForDisplay($dispatch->error_message, 500));
        }

        return self::SUCCESS;
    }

    /**
     * Display a dashboard with status counts and recent operations.
     */
    private function showDashboard(OperationsDispatchService $service): int
    {
        $counts = $service->statusCounts();
        $last24h = $service->countSince();

        $this->components->info('Operations Dispatch Dashboard');
        $this->newLine();

        // Status counts
        $countRows = [];

        foreach (OperationStatus::cases() as $status) {
            $countRows[] = [$status->label(), $counts[$status->value] ?? 0];
        }

        $this->components->twoColumnDetail('Last 24 hours', (string) $last24h);
        $this->table(['Status', 'Count'], $countRows);

        // Recent operations
        $typeFilter = $this->resolveTypeFilter();
        $statusFilter = $this->resolveStatusFilter();
        $limit = (int) $this->option('limit');

        $recent = $service->recent($typeFilter, $statusFilter, $limit);

        if ($recent->isEmpty()) {
            $this->components->info('No operations found matching filters.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Recent operations:');

        $rows = $recent->map(fn (OperationDispatch $d) => [
            $d->id,
            $d->operation_type->label(),
            $d->status->label(),
            $this->truncateForDisplay($d->task, 40),
            $d->employee_id ?? '-',
            $d->created_at?->diffForHumans() ?? '-',
        ])->all();

        $this->table(['ID', 'Type', 'Status', 'Task', 'Agent', 'Created'], $rows);

        return self::SUCCESS;
    }

    /**
     * Resolve the type filter from --type option.
     */
    private function resolveTypeFilter(): ?OperationType
    {
        $type = $this->option('type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        return OperationType::tryFrom($type);
    }

    /**
     * Resolve the status filter from --status option.
     */
    private function resolveStatusFilter(): ?OperationStatus
    {
        $status = $this->option('status');

        if (! is_string($status) || $status === '') {
            return null;
        }

        return OperationStatus::tryFrom($status);
    }

    /**
     * Truncate a string for display in the terminal.
     */
    private function truncateForDisplay(?string $text, int $maxLength): string
    {
        if ($text === null) {
            return '-';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
