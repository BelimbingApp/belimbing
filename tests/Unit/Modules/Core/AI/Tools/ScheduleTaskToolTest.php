<?php

use App\Modules\Core\AI\Models\ScheduleDefinition;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\Scheduling\ScheduleDefinitionService;
use App\Modules\Core\AI\Tools\ScheduleTaskTool;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const SCHED_TOOL_TASK = 'Test task';
const SCHED_TOOL_WEEKLY_CRON = '0 9 * * 1';
const SCHED_TOOL_PAYLOAD = 'Run the weekly report generation';
const SCHED_TOOL_PASSWORD_FIELD = 'password';
const SCHED_TOOL_NOT_FOUND = 'not found';

function makeScheduleServiceMock(): ScheduleDefinitionService
{
    return Mockery::mock(ScheduleDefinitionService::class);
}

function makeInactiveScheduleContext(): AgentExecutionContext
{
    return new AgentExecutionContext;
}

/**
 * Set a mock authenticated user with company context.
 *
 * Uses an anonymous Authenticatable class instead of Mockery because:
 * 1. Mockery mocks fail PHP 8.5 native type checks in SessionGuard::setUser()
 * 2. method_exists() returns false for Mockery's __call magic methods
 */
function actAsScheduleUser(int $companyId = 10): void
{
    $user = new class($companyId) implements Authenticatable
    {
        public function __construct(
            private readonly int $companyId,
            private readonly string $password = SCHED_TOOL_PASSWORD_FIELD,
        ) {}

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthPassword(): string
        {
            return $this->password;
        }

        public function getAuthPasswordName(): string
        {
            return SCHED_TOOL_PASSWORD_FIELD;
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void
        {
            unset($value);
        }

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }

        public function getCompanyId(): int
        {
            return $this->companyId;
        }
    };

    app('auth')->guard()->setUser($user);
}

function makeScheduleDefinitionStub(array $overrides = []): ScheduleDefinition
{
    $defaults = [
        'id' => 1,
        'company_id' => 10,
        'employee_id' => null,
        'description' => SCHED_TOOL_TASK,
        'execution_payload' => SCHED_TOOL_PAYLOAD,
        'cron_expression' => SCHED_TOOL_WEEKLY_CRON,
        'timezone' => 'UTC',
        'is_enabled' => true,
        'concurrency_policy' => 'skip',
        'last_fired_at' => null,
        'next_due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return ScheduleDefinition::unguarded(
        fn () => new ScheduleDefinition(array_merge($defaults, $overrides)),
    );
}

beforeEach(function () {
    $this->scheduleService = makeScheduleServiceMock();
    $this->executionContext = makeInactiveScheduleContext();
    $this->tool = new ScheduleTaskTool($this->scheduleService, $this->executionContext);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'schedule_task',
            'ai.tool_schedule.execute',
            ['action', 'task_id', 'description', 'execution_payload', 'cron_expression', 'agent_id', 'timezone', 'enabled'],
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
    it('returns empty task list when no company context', function () {
        // executionContext returns active=false, no auth user → null companyId
        $result = $this->tool->execute(['action' => 'list']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('company context');
    });

    it('returns task list with total via service', function () {
        $this->scheduleService->shouldReceive('list')
            ->with(10)
            ->andReturn(new Collection([makeScheduleDefinitionStub()]));

        // Provide a mock user with getCompanyId to resolve company context
        actAsScheduleUser();

        $result = $this->tool->execute(['action' => 'list']);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data)->toHaveKeys(['tasks', 'total'])
            ->and($data['total'])->toBe(1)
            ->and($data['tasks'][0]['task_id'])->toBe(1)
            ->and($data['tasks'][0]['description'])->toBe(SCHED_TOOL_TASK);
    });

    it('returns empty tasks array when service returns empty', function () {
        $this->scheduleService->shouldReceive('list')
            ->with(10)
            ->andReturn(new Collection);

        actAsScheduleUser();

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
            ['action' => 'add', 'description' => 'test', 'execution_payload' => 'do stuff'],
        );
    });

    it('rejects missing or empty execution_payload', function () {
        $this->assertRejectsMissingAndEmptyStringArgument(
            'execution_payload',
            ['action' => 'add', 'description' => 'test', 'cron_expression' => SCHED_TOOL_WEEKLY_CRON],
        );
    });

    it('rejects invalid cron expression via service', function () {
        $this->scheduleService->shouldReceive('create')
            ->andThrow(new InvalidArgumentException('Invalid cron expression: "not valid"'));

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'add',
            'description' => 'test',
            'execution_payload' => 'do stuff',
            'cron_expression' => 'not valid',
        ]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('cron');
    });

    it('creates schedule through service', function () {
        $schedule = makeScheduleDefinitionStub();

        $this->scheduleService->shouldReceive('create')
            ->once()
            ->andReturn($schedule);

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHED_TOOL_TASK,
            'execution_payload' => SCHED_TOOL_PAYLOAD,
            'cron_expression' => SCHED_TOOL_WEEKLY_CRON,
        ]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('created')
            ->and($data['task_id'])->toBe(1)
            ->and($data['description'])->toBe(SCHED_TOOL_TASK);
    });

    it('creates schedule with agent_id', function () {
        $schedule = makeScheduleDefinitionStub(['employee_id' => 42]);

        $this->scheduleService->shouldReceive('create')
            ->once()
            ->andReturn($schedule);

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHED_TOOL_TASK,
            'execution_payload' => SCHED_TOOL_PAYLOAD,
            'cron_expression' => SCHED_TOOL_WEEKLY_CRON,
            'agent_id' => 42,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['agent_id'])->toBe(42);
    });

    it('defaults enabled to true', function () {
        $schedule = makeScheduleDefinitionStub(['is_enabled' => true]);

        $this->scheduleService->shouldReceive('create')
            ->once()
            ->andReturn($schedule);

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHED_TOOL_TASK,
            'execution_payload' => SCHED_TOOL_PAYLOAD,
            'cron_expression' => SCHED_TOOL_WEEKLY_CRON,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['enabled'])->toBeTrue();
    });

    it('respects enabled false', function () {
        $schedule = makeScheduleDefinitionStub(['is_enabled' => false]);

        $this->scheduleService->shouldReceive('create')
            ->once()
            ->andReturn($schedule);

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'add',
            'description' => SCHED_TOOL_TASK,
            'execution_payload' => SCHED_TOOL_PAYLOAD,
            'cron_expression' => SCHED_TOOL_WEEKLY_CRON,
            'enabled' => false,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['enabled'])->toBeFalse();
    });
});

describe('update action', function () {
    it('rejects missing task_id for updates', function () {
        $result = $this->tool->execute(['action' => 'update']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('task_id');
    });

    it('rejects non-integer task_id', function () {
        $result = $this->tool->execute(['action' => 'update', 'task_id' => 'bad_id']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('task_id');
    });

    it('updates schedule through service', function () {
        $schedule = makeScheduleDefinitionStub(['description' => 'Updated description']);

        $this->scheduleService->shouldReceive('update')
            ->once()
            ->with(1, 10, Mockery::type('array'))
            ->andReturn($schedule);

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'update',
            'task_id' => 1,
            'description' => 'Updated description',
        ]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('updated');
    });

    it('returns not_found when service returns null', function () {
        $this->scheduleService->shouldReceive('update')
            ->once()
            ->andReturn(null);

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'update',
            'task_id' => 999,
            'description' => 'New desc',
        ]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain(SCHED_TOOL_NOT_FOUND);
    });

    it('rejects invalid cron expression in update', function () {
        $this->scheduleService->shouldReceive('update')
            ->andThrow(new InvalidArgumentException('Invalid cron expression'));

        actAsScheduleUser();

        $result = $this->tool->execute([
            'action' => 'update',
            'task_id' => 1,
            'cron_expression' => 'bad',
        ]);

        expect((string) $result)->toContain('Error');
    });
});

describe('remove action', function () {
    it('rejects missing task_id for removals', function () {
        $result = $this->tool->execute(['action' => 'remove']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('task_id');
    });

    it('removes schedule through service', function () {
        $this->scheduleService->shouldReceive('remove')
            ->once()
            ->with(1, 10)
            ->andReturn(true);

        actAsScheduleUser();

        $result = $this->tool->execute(['action' => 'remove', 'task_id' => 1]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('removed');
    });

    it('returns not_found when service returns false', function () {
        $this->scheduleService->shouldReceive('remove')
            ->once()
            ->andReturn(false);

        actAsScheduleUser();

        $result = $this->tool->execute(['action' => 'remove', 'task_id' => 999]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain(SCHED_TOOL_NOT_FOUND);
    });
});

describe('status action', function () {
    it('rejects missing task_id for status checks', function () {
        $result = $this->tool->execute(['action' => 'status']);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('task_id');
    });

    it('returns schedule status through service', function () {
        $schedule = makeScheduleDefinitionStub();

        $this->scheduleService->shouldReceive('find')
            ->once()
            ->with(1, 10)
            ->andReturn($schedule);

        actAsScheduleUser();

        $result = $this->tool->execute(['action' => 'status', 'task_id' => 1]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('found')
            ->and($data)->toHaveKey('checked_at');
    });

    it('returns not_found for unknown schedule', function () {
        $this->scheduleService->shouldReceive('find')
            ->once()
            ->andReturn(null);

        actAsScheduleUser();

        $result = $this->tool->execute(['action' => 'status', 'task_id' => 999]);
        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain(SCHED_TOOL_NOT_FOUND);
    });
});
