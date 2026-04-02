<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Tools\AbstractReadOnlyMemoryTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\Memory\MemoryHealthService;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Diagnostic tool exposing memory subsystem health for an agent.
 *
 * Returns index freshness, chunk count, stale source count,
 * embedding availability, and compaction status. Useful for
 * agents to assess their own memory state before searching.
 *
 * Gated by `ai.tool_memory_get.execute` authz capability (same as MemoryGetTool).
 */
class MemoryStatusTool extends AbstractReadOnlyMemoryTool
{
    private ?MemoryHealthService $healthService = null;

    /**
     * Inject the health service.
     */
    public function setHealthService(MemoryHealthService $service): void
    {
        $this->healthService = $service;
    }

    public function name(): string
    {
        return 'memory_status';
    }

    public function description(): string
    {
        return 'Check the health and freshness of the agent memory index. '
            .'Returns source count, chunk count, stale entries, last indexed time, '
            .'and embedding availability.';
    }

    protected function schema(): ?ToolSchemaBuilder
    {
        return null;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_memory_get.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Memory Status',
            'summary' => 'Report memory index health and freshness.',
            'explanation' => 'Returns diagnostic information about the agent memory index '
                .'including source count, chunk count, freshness, and embedding availability.',
            'setup_requirements' => [
                'Memory index built via blb:ai:memory:index',
            ],
            'test_examples' => [
                [
                    'label' => 'Check status',
                    'input' => [],
                ],
            ],
            'health_checks' => [
                'Memory index exists',
            ],
            'limits' => [
                'Read-only diagnostic — does not modify the index',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        if ($this->healthService === null) {
            return ToolResult::error('Memory health service not available.');
        }

        $employeeId = Employee::LARA_ID;
        $report = $this->healthService->report($employeeId);

        return ToolResult::success($this->formatReport($report->toArray()));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function formatReport(array $report): string
    {
        $lines = ['# Memory Status'];
        $lines[] = '';
        $lines[] = '- **Indexed:** '.($report['indexed'] ? 'Yes' : 'No');
        $lines[] = '- **Sources:** '.$report['source_count'];
        $lines[] = '- **Chunks:** '.$report['chunk_count'];
        $lines[] = '- **Stale sources:** '.$report['stale_source_count'];

        if ($report['last_indexed_at'] !== null) {
            $lines[] = '- **Last indexed:** '.date('Y-m-d H:i:s', $report['last_indexed_at']);
        } else {
            $lines[] = '- **Last indexed:** Never';
        }

        if ($report['last_compacted_at'] !== null) {
            $lines[] = '- **Last compacted:** '.date('Y-m-d H:i:s', $report['last_compacted_at']);
        } else {
            $lines[] = '- **Last compacted:** Never';
        }

        $lines[] = '- **Embeddings available:** '.($report['embeddings_available'] ? 'Yes' : 'No');

        return implode("\n", $lines);
    }
}
