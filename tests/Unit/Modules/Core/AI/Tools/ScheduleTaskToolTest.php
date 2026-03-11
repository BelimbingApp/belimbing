<?php

use App\Modules\Core\AI\Tools\ScheduleTaskTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const SCHEDULE_TEST_TASK = 'Test task';
const WEEKLY_CRON = '0 9 * * 1';

beforeEach(function () {
    $this->tool = new ScheduleTaskTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'schedule_task',
            'ai.tool_schedule.execute',
            ['action', 'task_id', 'description', 'cron_expression', 'worker_id', 'enabled'],
            ['action'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing action', function () {
        $this->assertToolError([]);
    });

    it('rejects invalid action', function () {
        $result = $this->tool->execute(['action' => 'bogus']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('must be one of');
    });
});

describe('list action', function () {
    it('returns task list with total', function () {
        $result = $this->tool->execute(['action' => 'list']);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data)->toHaveKeys(['tasks', 'total', 'message']);
    });

    it('returns empty tasks array', function () {
        $result = $this->tool->execute(['action' => 'list']);
        $data = json_decode((string) $result, true);

        expect($data['tasks'])->toBe([])
            ->and($data['total'])->toBe(0);
    });
});

describe('add action', function () {
    it('rejects missing or empty description', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('description', ['action' => 'add']);
    });

    it('rejects missing or empty cron_expression', function () {
        $this->assertRejectsMissingAndEmptyStringArgument(
            'cron_expression',
            ['action' => 'add', 'description' => 'test'],
        );
    });

    it('rejects invalid cron expression', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => 'test',
            'cron_expression' => 'not valid',
        ]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('cron');
    });

    it('accepts valid 5-field cron expression', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHEDULE_TEST_TASK,
            'cron_expression' => WEEKLY_CRON,
        ]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('created')
            ->and($data['task_id'])->toStartWith('sched_');
    });

    it('includes worker_id when provided', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHEDULE_TEST_TASK,
            'cron_expression' => WEEKLY_CRON,
            'worker_id' => 42,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['worker_id'])->toBe(42);
    });

    it('defaults enabled to true', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHEDULE_TEST_TASK,
            'cron_expression' => WEEKLY_CRON,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['enabled'])->toBeTrue();
    });

    it('respects enabled false', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHEDULE_TEST_TASK,
            'cron_expression' => WEEKLY_CRON,
            'enabled' => false,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['enabled'])->toBeFalse();
    });
});

describe('update action', function () {
    it('rejects missing or empty task_id for updates', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('task_id', ['action' => 'update']);
    });

    it('rejects invalid task_id format', function () {
        $result = $this->tool->execute(['action' => 'update', 'task_id' => 'bad_id']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('Invalid task_id');
    });

    it('accepts valid task_id', function () {
        $result = $this->tool->execute(['action' => 'update', 'task_id' => 'sched_abc123def456']);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('updated');
    });

    it('rejects invalid cron expression in update', function () {
        $result = $this->tool->execute([
            'action' => 'update',
            'task_id' => 'sched_abc123def456',
            'cron_expression' => 'bad',
        ]);

        expect((string) $result)->toContain('Error');
    });
});

describe('remove action', function () {
    it('rejects missing or empty task_id for removals', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('task_id', ['action' => 'remove']);
    });

    it('accepts valid task_id', function () {
        $result = $this->tool->execute(['action' => 'remove', 'task_id' => 'sched_abc123def456']);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('removed');
    });
});

describe('status action', function () {
    it('rejects missing or empty task_id for status checks', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('task_id', ['action' => 'status']);
    });

    it('returns status for valid task_id', function () {
        $result = $this->tool->execute(['action' => 'status', 'task_id' => 'sched_abc123def456']);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('unknown')
            ->and($data)->toHaveKey('checked_at');
    });
});

describe('cron expression validation', function () {
    it('accepts standard cron patterns', function () {
        $patterns = [
            '* * * * *',
            WEEKLY_CRON,
            '*/5 * * * *',
            '0 0 1,15 * *',
            '30 6 * * 1-5',
        ];

        foreach ($patterns as $pattern) {
            $result = $this->tool->execute([
                'action' => 'add',
                'description' => SCHEDULE_TEST_TASK,
                'cron_expression' => $pattern,
            ]);
            $data = json_decode((string) $result, true);

            expect($data)->not->toBeNull("Failed for pattern: {$pattern}")
                ->and($data['status'])->toBe('created', "Failed for pattern: {$pattern}");
        }
    });

    it('rejects cron with wrong field count', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHEDULE_TEST_TASK,
            'cron_expression' => '* * *',
        ]);

        expect((string) $result)->toContain('Error');
    });

    it('rejects cron with letters', function () {
        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHEDULE_TEST_TASK,
            'cron_expression' => 'a b c d e',
        ]);

        expect((string) $result)->toContain('Error');
    });
});
