<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\HasToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;

/**
 * Agent discovery tool for Lara and other agents.
 *
 * Lists available Agents that the current user can delegate tasks
 * to, including each agent's name and capability summary. This enables
 * Lara to discover available agents before dispatching delegation tasks.
 *
 * Gated by `ai.tool_agent_list.execute` authz capability.
 */
class AgentListTool extends AbstractTool
{
    use HasToolMetadata;

    public function __construct(
        private readonly LaraCapabilityMatcher $capabilityMatcher,
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
        $agents = $this->capabilityMatcher->discoverDelegableAgentsForCurrentUser();

        if ($agents === []) {
            return ToolResult::success(
                'No Agents available for delegation. '
                    .'The current user has no accessible Agents.'
            );
        }

        $filter = $this->optionalString($arguments, 'capability_filter');

        if ($filter !== null) {
            $agents = $this->filterAgents($agents, $filter);

            if ($agents === []) {
                return ToolResult::success(
                    'No Agents match the filter "'.$filter.'". '
                        .'Try again without a filter to see all available agents.'
                );
            }
        }

        return ToolResult::success($this->formatAgentList($agents));
    }

    /**
     * Filter agents whose capability summary contains the keyword (case-insensitive).
     *
     * @param  list<array{employee_id: int, name: string, capability_summary: string}>  $agents
     * @return list<array{employee_id: int, name: string, capability_summary: string}>
     */
    private function filterAgents(array $agents, string $filter): array
    {
        $normalizedFilter = mb_strtolower($filter);

        return array_values(array_filter(
            $agents,
            fn (array $agent): bool => str_contains(
                mb_strtolower($agent['capability_summary']),
                $normalizedFilter,
            ),
        ));
    }

    /**
     * Format the agent list as a readable numbered list.
     *
     * @param  list<array{employee_id: int, name: string, capability_summary: string}>  $agents
     */
    private function formatAgentList(array $agents): string
    {
        $count = count($agents);
        $output = $count.' Agent'.($count !== 1 ? 's' : '').' available:'."\n";

        foreach ($agents as $index => $agent) {
            $number = $index + 1;
            $output .= "\n".$number.'. **'.$agent['name'].'** (ID: '.$agent['employee_id'].')'
                ."\n".'   '.$agent['capability_summary']."\n";
        }

        return $output;
    }
}
