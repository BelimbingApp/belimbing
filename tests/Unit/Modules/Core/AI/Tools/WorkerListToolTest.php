<?php

use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Tools\WorkerListTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const WORKER_LIST_DATA_ANALYST = 'Data Analyst';
const WORKER_LIST_CODE_REVIEWER = 'Code Reviewer';

beforeEach(function () {
    $this->matcher = Mockery::mock(LaraCapabilityMatcher::class);
    $this->tool = new WorkerListTool($this->matcher);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'worker_list',
            'ai.tool_worker_list.execute',
            ['capability_filter'],
            [],
        );
    });
});

describe('worker discovery', function () {
    it('returns message when no workers available', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([]);

        $result = $this->tool->execute([]);

        expect((string) $result)->toContain('No Digital Workers available');
    });

    it('lists available workers', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => WORKER_LIST_DATA_ANALYST, 'capability_summary' => 'Analyzes data and generates reports'],
                ['employee_id' => 2, 'name' => WORKER_LIST_CODE_REVIEWER, 'capability_summary' => 'Reviews code for quality'],
            ]);

        $result = $this->tool->execute([]);

        expect((string) $result)->toContain('2 Digital Workers available')
            ->and((string) $result)->toContain(WORKER_LIST_DATA_ANALYST)
            ->and((string) $result)->toContain('ID: 1')
            ->and((string) $result)->toContain(WORKER_LIST_CODE_REVIEWER)
            ->and((string) $result)->toContain('ID: 2')
            ->and((string) $result)->toContain('Analyzes data');
    });

    it('shows singular form for one worker', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 5, 'name' => 'Solo Worker', 'capability_summary' => 'General tasks'],
            ]);

        $result = $this->tool->execute([]);

        expect((string) $result)->toContain('1 Digital Worker available')
            ->and((string) $result)->not->toContain('Workers available');
    });
});

describe('capability filtering', function () {
    it('filters workers by capability keyword', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => WORKER_LIST_DATA_ANALYST, 'capability_summary' => 'Analyzes data and generates reports'],
                ['employee_id' => 2, 'name' => WORKER_LIST_CODE_REVIEWER, 'capability_summary' => 'Reviews code for quality'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 'data']);

        expect((string) $result)->toContain(WORKER_LIST_DATA_ANALYST)
            ->and((string) $result)->not->toContain(WORKER_LIST_CODE_REVIEWER);
    });

    it('performs case-insensitive filtering', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => WORKER_LIST_DATA_ANALYST, 'capability_summary' => 'Analyzes DATA reports'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 'data']);

        expect((string) $result)->toContain(WORKER_LIST_DATA_ANALYST);
    });

    it('returns no match message when filter excludes all workers', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => WORKER_LIST_DATA_ANALYST, 'capability_summary' => 'Analyzes data'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 'nonexistent']);

        expect((string) $result)->toContain('No Digital Workers match the filter');
    });

    it('ignores empty capability filter', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Worker', 'capability_summary' => 'General'],
            ]);

        $result = $this->tool->execute(['capability_filter' => '']);

        expect((string) $result)->toContain('Worker');
    });

    it('ignores non-string capability filter', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Worker', 'capability_summary' => 'General'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 123]);

        expect((string) $result)->toContain('Worker');
    });
});
