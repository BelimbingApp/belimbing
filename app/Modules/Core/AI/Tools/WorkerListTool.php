<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;

/**
 * Digital Worker discovery tool for Lara and other DWs.
 *
 * Lists available Digital Workers that the current user can delegate tasks
 * to, including each worker's name and capability summary. This enables
 * Lara to discover available DWs before dispatching delegation tasks.
 *
 * Gated by `ai.tool_worker_list.execute` authz capability.
 */
class WorkerListTool extends AbstractTool
{
    public function __construct(
        private readonly LaraCapabilityMatcher $capabilityMatcher,
    ) {}

    public function name(): string
    {
        return 'worker_list';
    }

    public function description(): string
    {
        return 'List available Digital Workers that you can delegate tasks to. '
            .'Returns each worker\'s ID, name, and capability summary. '
            .'Use this before delegate_task to discover which workers are available '
            .'and find the best match for a given task.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'capability_filter',
                'Optional keyword to filter workers by capability summary. '
                    .'Only workers whose capability summary contains this keyword will be returned.'
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DELEGATION;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_worker_list.execute';
    }

    protected function handle(array $arguments): ToolResult
    {
        $workers = $this->capabilityMatcher->discoverDelegableWorkersForCurrentUser();

        if ($workers === []) {
            return ToolResult::success(
                'No Digital Workers available for delegation. '
                    .'The current user has no accessible Digital Workers.'
            );
        }

        $filter = $this->optionalString($arguments, 'capability_filter');

        if ($filter !== null) {
            $workers = $this->filterWorkers($workers, $filter);

            if ($workers === []) {
                return ToolResult::success(
                    'No Digital Workers match the filter "'.$filter.'". '
                        .'Try again without a filter to see all available workers.'
                );
            }
        }

        return ToolResult::success($this->formatWorkerList($workers));
    }

    /**
     * Filter workers whose capability summary contains the keyword (case-insensitive).
     *
     * @param  list<array{employee_id: int, name: string, capability_summary: string}>  $workers
     * @return list<array{employee_id: int, name: string, capability_summary: string}>
     */
    private function filterWorkers(array $workers, string $filter): array
    {
        $normalizedFilter = mb_strtolower($filter);

        return array_values(array_filter(
            $workers,
            fn (array $worker): bool => str_contains(
                mb_strtolower($worker['capability_summary']),
                $normalizedFilter,
            ),
        ));
    }

    /**
     * Format the worker list as a readable numbered list.
     *
     * @param  list<array{employee_id: int, name: string, capability_summary: string}>  $workers
     */
    private function formatWorkerList(array $workers): string
    {
        $count = count($workers);
        $output = $count.' Digital Worker'.($count !== 1 ? 's' : '').' available:'."\n";

        foreach ($workers as $index => $worker) {
            $number = $index + 1;
            $output .= "\n".$number.'. **'.$worker['name'].'** (ID: '.$worker['employee_id'].')'
                ."\n".'   '.$worker['capability_summary']."\n";
        }

        return $output;
    }
}
