<?php

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Modules\Core\AI\Tools\AgentListTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const AGENT_LIST_DATA_ANALYST = 'Data Analyst';
const AGENT_LIST_CODE_REVIEWER = 'Code Reviewer';
const AGENT_LIST_ANALYZES_DATA_SUMMARY = 'Data Analyst — Analyzes data and generates reports';
const AGENT_LIST_REVIEWS_CODE_SUMMARY = 'Code Reviewer — Reviews code for quality';

function makeAgentDescriptor(int $employeeId, string $name, string $displaySummary, array $domains = [], array $taskTypes = []): AgentCapabilityDescriptor
{
    return new AgentCapabilityDescriptor(
        employeeId: $employeeId,
        name: $name,
        domains: $domains,
        taskTypes: $taskTypes,
        displaySummary: $displaySummary,
    );
}

beforeEach(function () {
    $this->catalog = Mockery::mock(AgentCapabilityCatalog::class);
    $this->tool = new AgentListTool($this->catalog);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'agent_list',
            'ai.tool_agent_list.execute',
            ['capability_filter'],
            [],
        );
    });
});

describe('agent discovery', function () {
    it('returns message when no agents available', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([]);

        $result = $this->tool->execute([]);

        expect((string) $result)->toContain('No Agents available');
    });

    it('lists available agents with structured data', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(1, AGENT_LIST_DATA_ANALYST, AGENT_LIST_ANALYZES_DATA_SUMMARY, ['data_analysis'], ['generate_report']),
                makeAgentDescriptor(2, AGENT_LIST_CODE_REVIEWER, AGENT_LIST_REVIEWS_CODE_SUMMARY, ['engineering']),
            ]);

        $result = $this->tool->execute([]);

        expect((string) $result)->toContain('2 Agents available')
            ->and((string) $result)->toContain(AGENT_LIST_DATA_ANALYST)
            ->and((string) $result)->toContain('ID: 1')
            ->and((string) $result)->toContain(AGENT_LIST_CODE_REVIEWER)
            ->and((string) $result)->toContain('ID: 2')
            ->and((string) $result)->toContain('Analyzes data')
            ->and((string) $result)->toContain('Domains: data_analysis')
            ->and((string) $result)->toContain('Task types: generate_report');
    });

    it('shows singular form for one agent', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(5, 'Solo Agent', 'General tasks'),
            ]);

        $result = $this->tool->execute([]);

        expect((string) $result)->toContain('1 Agent available')
            ->and((string) $result)->not->toContain('Agents available');
    });
});

describe('capability filtering', function () {
    it('filters agents by display summary keyword', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(1, AGENT_LIST_DATA_ANALYST, AGENT_LIST_ANALYZES_DATA_SUMMARY),
                makeAgentDescriptor(2, AGENT_LIST_CODE_REVIEWER, AGENT_LIST_REVIEWS_CODE_SUMMARY),
            ]);

        $result = $this->tool->execute(['capability_filter' => 'data']);

        expect((string) $result)->toContain(AGENT_LIST_DATA_ANALYST)
            ->and((string) $result)->not->toContain(AGENT_LIST_CODE_REVIEWER);
    });

    it('performs case-insensitive filtering', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(1, AGENT_LIST_DATA_ANALYST, 'Analyzes DATA reports'),
            ]);

        $result = $this->tool->execute(['capability_filter' => 'data']);

        expect((string) $result)->toContain(AGENT_LIST_DATA_ANALYST);
    });

    it('returns no match message when filter excludes all agents', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(1, AGENT_LIST_DATA_ANALYST, 'Analyzes data'),
            ]);

        $result = $this->tool->execute(['capability_filter' => 'nonexistent']);

        expect((string) $result)->toContain('No Agents match the filter');
    });

    it('ignores empty capability filter', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(1, 'Agent', 'General'),
            ]);

        $result = $this->tool->execute(['capability_filter' => '']);

        expect((string) $result)->toContain('Agent');
    });

    it('ignores non-string capability filter', function () {
        $this->catalog->shouldReceive('delegableDescriptorsForCurrentUser')
            ->once()
            ->andReturn([
                makeAgentDescriptor(1, 'Agent', 'General'),
            ]);

        $result = $this->tool->execute(['capability_filter' => 123]);

        expect((string) $result)->toContain('Agent');
    });
});
