<?php

use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\BackgroundCommandService;
use App\Modules\Core\AI\Tools\ArtisanTool;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Process;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const ARTISAN_ROUTES_OUTPUT = 'routes output';
const ARTISAN_COMMAND_NOT_FOUND = 'Command not found';

beforeEach(function () {
    $this->backgroundService = Mockery::mock(BackgroundCommandService::class);
    $this->tool = new ArtisanTool($this->backgroundService);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'artisan',
            'ai.tool_artisan.execute',
            ['command', 'timeout', 'background'],
            ['command'],
        );

        expect($this->tool->description())->toContain('background');
    });

    it('declares timeout as integer type', function () {
        $schema = $this->tool->parametersSchema();
        expect($schema['properties']['timeout']['type'])->toBe('integer');
    });

    it('declares background as boolean type', function () {
        $schema = $this->tool->parametersSchema();
        expect($schema['properties']['background']['type'])->toBe('boolean');
    });
});

describe('input validation', function () {
    it('rejects missing or empty command', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('command');
    });

    it('rejects non-string command', function () {
        $result = $this->tool->execute(['command' => 42]);
        expect((string) $result)->toContain('Error');
    });

    it('rejects whitespace-only command', function () {
        $result = $this->tool->execute(['command' => '   ']);
        expect((string) $result)->toContain('Error');
    });

    it('strips php artisan prefix', function () {
        Process::fake([
            'php artisan route:list' => Process::result(ARTISAN_ROUTES_OUTPUT),
        ]);

        $result = $this->tool->execute(['command' => 'php artisan route:list']);
        expect((string) $result)->toBe(ARTISAN_ROUTES_OUTPUT);
    });

    it('strips artisan prefix without php', function () {
        Process::fake([
            'php artisan route:list' => Process::result(ARTISAN_ROUTES_OUTPUT),
        ]);

        $result = $this->tool->execute(['command' => 'artisan route:list']);
        expect((string) $result)->toBe(ARTISAN_ROUTES_OUTPUT);
    });

    it('rejects artisan-only command that becomes empty after parsing', function () {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: ARTISAN_COMMAND_NOT_FOUND, exitCode: 1),
        ]);

        $result = $this->tool->execute(['command' => '  ']);
        expect((string) $result)->toContain('Error');
    });
});

describe('foreground execution', function () {
    it('executes command and returns output', function () {
        Process::fake([
            'php artisan route:list' => Process::result('Routes listed'),
        ]);

        $result = $this->tool->execute(['command' => 'route:list']);
        expect((string) $result)->toBe('Routes listed');
    });

    it('returns error output on failure', function () {
        Process::fake([
            'php artisan bad:command' => Process::result(
                output: '',
                errorOutput: ARTISAN_COMMAND_NOT_FOUND,
                exitCode: 1,
            ),
        ]);

        $result = $this->tool->execute(['command' => 'bad:command']);
        expect((string) $result)->toContain('failed')
            ->and((string) $result)->toContain(ARTISAN_COMMAND_NOT_FOUND);
    });

    it('returns success message for empty output', function () {
        Process::fake([
            'php artisan cache:clear' => Process::result(''),
        ]);

        $result = $this->tool->execute(['command' => 'cache:clear']);
        expect((string) $result)->toContain('successfully');
    });

    it('returns error output on failure with both outputs', function () {
        Process::fake([
            'php artisan fail:cmd' => Process::result(
                output: 'partial output',
                errorOutput: 'error details',
                exitCode: 1,
            ),
        ]);

        $result = $this->tool->execute(['command' => 'fail:cmd']);
        expect((string) $result)->toContain('failed')
            ->and((string) $result)->toContain('error details')
            ->and((string) $result)->toContain('partial output');
    });

    it('uses default timeout of 30 seconds', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'test:cmd']);

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'php artisan test:cmd');
        });
    });
});

describe('timeout parameter', function () {
    it('accepts custom timeout', function () {
        Process::fake([
            'php artisan long:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'long:cmd',
            'timeout' => 120,
        ]);

        expect((string) $result)->toBe('done');
    });

    it('clamps timeout to minimum of 1 second', function () {
        Process::fake([
            'php artisan quick:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'quick:cmd',
            'timeout' => 0,
        ]);

        expect((string) $result)->toBe('done');
    });

    it('clamps timeout to maximum of 300 seconds', function () {
        Process::fake([
            'php artisan slow:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'slow:cmd',
            'timeout' => 999,
        ]);

        expect((string) $result)->toBe('done');
    });

    it('falls back to default for non-integer timeout', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'test:cmd',
            'timeout' => 'fast',
        ]);

        expect((string) $result)->toBe('done');
    });
});

describe('background execution', function () {
    it('returns dispatch_id immediately', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_migrate123',
            'task' => 'php artisan migrate',
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->with('migrate', null)
            ->andReturn($dispatch);

        $this->actingAs(User::factory()->make());

        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('dispatched')
            ->and($data['dispatch_id'])->toStartWith('op_')
            ->and($data['command'])->toBe('php artisan migrate');
    });

    it('returns message with dispatch instructions', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_migrate456',
            'task' => 'php artisan migrate',
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['message'])->toContain('delegation_status');
    });

    it('does not execute process for background commands', function () {
        Process::fake();

        $dispatch = new OperationDispatch([
            'id' => 'op_bg_no_exec',
            'task' => 'php artisan migrate',
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andReturn($dispatch);

        $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);

        Process::assertDidntRun('php artisan migrate');
    });

    it('returns policy_denied for disallowed commands', function () {
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException('Command "db:wipe" is not permitted for background execution.'));

        $result = $this->tool->execute([
            'command' => 'db:wipe',
            'background' => true,
        ]);
        $data = json_decode((string) $result, true);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('not permitted');
    });

    it('strips prefix before dispatching', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_stripped',
            'task' => 'php artisan migrate --seed',
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->with('migrate --seed', null)
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'command' => 'php artisan migrate --seed',
            'background' => true,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['command'])->toBe('php artisan migrate --seed');
    });

    it('ignores timeout when background is true', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_timeout',
            'task' => 'php artisan migrate',
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
            'timeout' => 120,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['status'])->toBe('dispatched');
    });
});

describe('output format', function () {
    it('trims output whitespace', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result("  output with spaces  \n"),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect((string) $result)->toBe('output with spaces');
    });

    it('prefers stdout over stderr for successful commands', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result(
                output: 'stdout content',
                errorOutput: 'stderr content',
            ),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect((string) $result)->toBe('stdout content');
    });

    it('falls back to stderr when stdout is empty', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result(
                output: '',
                errorOutput: 'stderr only',
            ),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect((string) $result)->toBe('stderr only');
    });

    it('returns valid JSON for background execution', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_json',
            'task' => 'php artisan migrate',
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);

        expect(json_decode((string) $result, true))->not->toBeNull();
    });
});
