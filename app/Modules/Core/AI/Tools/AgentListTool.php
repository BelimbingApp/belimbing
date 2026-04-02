<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;

/**
 * Agent discovery tool for Lara and other agents.
 *
 * Lists available Agents that the current user can delegate tasks
 * to, including each agent's name, capability summary, and structured
 * capability data. Uses the AgentCapabilityCatalog for richer
 * discovery than the legacy keyword matcher.
 *
 * Gated by `ai.tool_agent_list.execute` authz capability.
 */
class AgentListTool extends AbstractTool
{
    use ProvidesToolMetadata;

    public function __construct(
        private readonly AgentCapabilityCatalog $catalog,
    ) {}

    public function name(): string
    {
        return 'agent_list';
    }

    public function description(): string
    {
        return 'List available agents that you can delegate tasks to. '
            .'Returns each agent\'s ID, name, and capability summary. '
            .'Use this before delegate_task to discover which agents are available '
            .'and find the best match for a given task.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'capability_filter',
                'Optional keyword to filter agents by capability summary. '
                    .'Only agents whose capability summary contains this keyword will be returned.'
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
        return 'ai.tool_agent_list.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Agent List',
            'summary' => 'List available Agents that can receive delegated tasks.',
            'explanation' => 'Returns a list of Agents the current user supervises, along with '
                .'their capabilities and status. Useful for deciding which agent to delegate a task to.',
            'limits' => [
                'Shows supervised agents only',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $descriptors = $this->catalog->delegableDescriptorsForCurrentUser();

        if ($descriptors === []) {
            return ToolResult::success(
                'No Agents available for delegation. '
                    .'The current user has no accessible Agents.'
            );
        }

        $filter = $this->optionalString($arguments, 'capability_filter');

        if ($filter !== null) {
            $descriptors = $this->filterDescriptors($descriptors, $filter);

            if ($descriptors === []) {
                return ToolResult::success(
                    'No Agents match the filter "'.$filter.'". '
                        .'Try again without a filter to see all available agents.'
                );
            }
        }

        return ToolResult::success($this->formatDescriptorList($descriptors));
    }

    /**
     * Filter descriptors whose display summary contains the keyword (case-insensitive).
     *
     * @param  list<AgentCapabilityDescriptor>  $descriptors
     * @return list<AgentCapabilityDescriptor>
     */
    private function filterDescriptors(array $descriptors, string $filter): array
    {
        $normalizedFilter = mb_strtolower($filter);

        return array_values(array_filter(
            $descriptors,
            fn (AgentCapabilityDescriptor $descriptor): bool => str_contains(
                mb_strtolower($descriptor->displaySummary ?? ''),
                $normalizedFilter,
            ),
        ));
    }

    /**
     * Format the descriptor list as a readable numbered list.
     *
     * Includes structured capability data (domains, task types, specialties)
     * when available, providing richer discovery than bare summaries.
     *
     * @param  list<AgentCapabilityDescriptor>  $descriptors
     */
    private function formatDescriptorList(array $descriptors): string
    {
        $count = count($descriptors);
        $output = $count.' Agent'.($count !== 1 ? 's' : '').' available:'."\n";

        foreach ($descriptors as $index => $descriptor) {
            $number = $index + 1;
            $output .= "\n".$number.'. **'.$descriptor->name.'** (ID: '.$descriptor->employeeId.')'
                ."\n".'   '.($descriptor->displaySummary ?? 'General Agent');

            if ($descriptor->domains !== []) {
                $output .= "\n".'   Domains: '.implode(', ', $descriptor->domains);
            }

            if ($descriptor->taskTypes !== []) {
                $output .= "\n".'   Task types: '.implode(', ', $descriptor->taskTypes);
            }

            $output .= "\n";
        }

        return $output;
    }
}
