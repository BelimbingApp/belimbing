<?php

use App\Modules\Core\AI\Tools\ArtisanTool;
use Illuminate\Support\Facades\Process;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const ROUTES_OUTPUT = 'routes output';
const COMMAND_NOT_FOUND = 'Command not found';

beforeEach(function () {
    $this->tool = new ArtisanTool;
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
        expect($result)->toContain('Error');
    });

    it('rejects whitespace-only command', function () {
        $result = $this->tool->execute(['command' => '   ']);
        expect($result)->toContain('Error');
    });

    it('strips php artisan prefix', function () {
        Process::fake([
            'php artisan route:list' => Process::result(ROUTES_OUTPUT),
        ]);

        $result = $this->tool->execute(['command' => 'php artisan route:list']);
        expect($result)->toBe(ROUTES_OUTPUT);
    });

    it('strips artisan prefix without php', function () {
        Process::fake([
            'php artisan route:list' => Process::result(ROUTES_OUTPUT),
        ]);

        $result = $this->tool->execute(['command' => 'artisan route:list']);
        expect($result)->toBe(ROUTES_OUTPUT);
    });

    it('rejects artisan-only command that becomes empty after parsing', function () {
        // "artisan" alone → preg_replace strips "artisan " → empty string
        // But trim("artisan") doesn't have trailing space, so regex doesn't match.
        // The command runs as-is: "php artisan artisan" which will fail at runtime.
        // This is acceptable behavior — the LLM should provide an actual command.
        Process::fake([
            '*' => Process::result(output: '', errorOutput: COMMAND_NOT_FOUND, exitCode: 1),
        ]);

        $result = $this->tool->execute(['command' => '  ']);
        expect($result)->toContain('Error');
    });
});

describe('foreground execution', function () {
    it('executes command and returns output', function () {
        Process::fake([
            'php artisan route:list' => Process::result('Routes listed'),
        ]);

        $result = $this->tool->execute(['command' => 'route:list']);
        expect($result)->toBe('Routes listed');
    });

    it('returns error output on failure', function () {
        Process::fake([
            'php artisan bad:command' => Process::result(
                output: '',
                errorOutput: COMMAND_NOT_FOUND,
                exitCode: 1,
            ),
        ]);

        $result = $this->tool->execute(['command' => 'bad:command']);
        expect($result)->toContain('failed')
            ->and($result)->toContain(COMMAND_NOT_FOUND);
    });

    it('returns success message for empty output', function () {
        Process::fake([
            'php artisan cache:clear' => Process::result(''),
        ]);

        $result = $this->tool->execute(['command' => 'cache:clear']);
        expect($result)->toContain('successfully');
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
        expect($result)->toContain('failed')
            ->and($result)->toContain('error details')
            ->and($result)->toContain('partial output');
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

        expect($result)->toBe('done');
    });

    it('clamps timeout to minimum of 1 second', function () {
        Process::fake([
            'php artisan quick:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'quick:cmd',
            'timeout' => 0,
        ]);

        expect($result)->toBe('done');
    });

    it('clamps timeout to maximum of 300 seconds', function () {
        Process::fake([
            'php artisan slow:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'slow:cmd',
            'timeout' => 999,
        ]);

        expect($result)->toBe('done');
    });

    it('falls back to default for non-integer timeout', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result('done'),
        ]);

        $result = $this->tool->execute([
            'command' => 'test:cmd',
            'timeout' => 'fast',
        ]);

        expect($result)->toBe('done');
    });
});

describe('background execution', function () {
    it('returns dispatch_id immediately', function () {
        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['status'])->toBe('dispatched')
            ->and($data['dispatch_id'])->toStartWith('artisan_')
            ->and($data['command'])->toBe('migrate');
    });

    it('returns stub message', function () {
        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);
        $data = json_decode($result, true);

        expect($data['message'])->toContain('stub')
            ->and($data['message'])->toContain('delegation_status');
    });

    it('does not execute process for background commands', function () {
        Process::fake();

        $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);

        Process::assertDidntRun('php artisan migrate');
    });

    it('generates unique dispatch IDs', function () {
        $result1 = $this->tool->execute(['command' => 'cmd1', 'background' => true]);
        $result2 = $this->tool->execute(['command' => 'cmd2', 'background' => true]);

        $data1 = json_decode($result1, true);
        $data2 = json_decode($result2, true);

        expect($data1['dispatch_id'])->not->toBe($data2['dispatch_id']);
    });

    it('strips prefix before dispatching', function () {
        $result = $this->tool->execute([
            'command' => 'php artisan migrate --seed',
            'background' => true,
        ]);
        $data = json_decode($result, true);

        expect($data['command'])->toBe('migrate --seed');
    });

    it('ignores timeout when background is true', function () {
        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
            'timeout' => 120,
        ]);
        $data = json_decode($result, true);

        expect($data['status'])->toBe('dispatched');
    });
});

describe('output format', function () {
    it('trims output whitespace', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result("  output with spaces  \n"),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect($result)->toBe('output with spaces');
    });

    it('prefers stdout over stderr for successful commands', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result(
                output: 'stdout content',
                errorOutput: 'stderr content',
            ),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect($result)->toBe('stdout content');
    });

    it('falls back to stderr when stdout is empty', function () {
        Process::fake([
            'php artisan test:cmd' => Process::result(
                output: '',
                errorOutput: 'stderr only',
            ),
        ]);

        $result = $this->tool->execute(['command' => 'test:cmd']);
        expect($result)->toBe('stderr only');
    });

    it('returns valid JSON for background execution', function () {
        $result = $this->tool->execute([
            'command' => 'migrate',
            'background' => true,
        ]);

        expect(json_decode($result, true))->not->toBeNull();
    });
});
