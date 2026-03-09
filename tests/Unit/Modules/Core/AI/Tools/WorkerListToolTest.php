<?php

use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Tools\WorkerListTool;
use Tests\TestCase;
use Tests\Support\AssertsToolBehavior;

uses(TestCase::class, AssertsToolBehavior::class);

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

        expect($result)->toContain('No Digital Workers available');
    });

    it('lists available workers', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Data Analyst', 'capability_summary' => 'Analyzes data and generates reports'],
                ['employee_id' => 2, 'name' => 'Code Reviewer', 'capability_summary' => 'Reviews code for quality'],
            ]);

        $result = $this->tool->execute([]);

        expect($result)->toContain('2 Digital Workers available')
            ->and($result)->toContain('Data Analyst')
            ->and($result)->toContain('ID: 1')
            ->and($result)->toContain('Code Reviewer')
            ->and($result)->toContain('ID: 2')
            ->and($result)->toContain('Analyzes data');
    });

    it('shows singular form for one worker', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 5, 'name' => 'Solo Worker', 'capability_summary' => 'General tasks'],
            ]);

        $result = $this->tool->execute([]);

        expect($result)->toContain('1 Digital Worker available')
            ->and($result)->not->toContain('Workers available');
    });
});

describe('capability filtering', function () {
    it('filters workers by capability keyword', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Data Analyst', 'capability_summary' => 'Analyzes data and generates reports'],
                ['employee_id' => 2, 'name' => 'Code Reviewer', 'capability_summary' => 'Reviews code for quality'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 'data']);

        expect($result)->toContain('Data Analyst')
            ->and($result)->not->toContain('Code Reviewer');
    });

    it('performs case-insensitive filtering', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Data Analyst', 'capability_summary' => 'Analyzes DATA reports'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 'data']);

        expect($result)->toContain('Data Analyst');
    });

    it('returns no match message when filter excludes all workers', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Data Analyst', 'capability_summary' => 'Analyzes data'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 'nonexistent']);

        expect($result)->toContain('No Digital Workers match the filter');
    });

    it('ignores empty capability filter', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Worker', 'capability_summary' => 'General'],
            ]);

        $result = $this->tool->execute(['capability_filter' => '']);

        expect($result)->toContain('Worker');
    });

    it('ignores non-string capability filter', function () {
        $this->matcher->shouldReceive('discoverDelegableWorkersForCurrentUser')
            ->once()
            ->andReturn([
                ['employee_id' => 1, 'name' => 'Worker', 'capability_summary' => 'General'],
            ]);

        $result = $this->tool->execute(['capability_filter' => 123]);

        expect($result)->toContain('Worker');
    });
});
