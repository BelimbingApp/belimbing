<?php

use App\Base\Foundation\RuntimeRequirements\RuntimeRequirementVerifier;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    putenv('BLB_SYSTEM_PHP_BINARY');
});

it('accepts a system PHP CLI with both SQL Server extensions and PDO driver', function (): void {
    putenv('BLB_SYSTEM_PHP_BINARY=system-php');
    Process::fake(function ($process) {
        return match ($process->command) {
            ['system-php', '-m'] => Process::result("PDO\nsqlsrv\npdo_sqlsrv\n"),
            ['system-php', '-r', 'echo implode(",", PDO::getAvailableDrivers());'] => Process::result('sqlite,sqlsrv'),
            default => Process::result(exitCode: 1),
        };
    });

    expect((new RuntimeRequirementVerifier)->unmet([RuntimeRequirementVerifier::PHP_CLI_SQLSRV]))->toBe([]);
});

it('reports the SQL Server CLI profile when its PDO driver is missing', function (): void {
    Process::fake(function ($process) {
        return match ($process->command) {
            ['php', '-m'] => Process::result("PDO\nsqlsrv\npdo_sqlsrv\n"),
            ['php', '-r', 'echo implode(",", PDO::getAvailableDrivers());'] => Process::result('sqlite'),
            default => Process::result(exitCode: 1),
        };
    });

    expect((new RuntimeRequirementVerifier)->unmet([RuntimeRequirementVerifier::PHP_CLI_SQLSRV]))
        ->toBe(['The system PHP CLI does not have sqlsrv and pdo_sqlsrv available.']);
});

it('reports and provisions the mbstring CLI profile separately', function (): void {
    Process::fake(function ($process) {
        return match ($process->command) {
            ['php', '-m'] => Process::result("PDO\n"),
            default => Process::result(exitCode: 1),
        };
    });

    $verifier = new RuntimeRequirementVerifier;

    expect($verifier->unmet([RuntimeRequirementVerifier::PHP_CLI_MBSTRING]))
        ->toBe(['The system PHP CLI does not have mbstring available.'])
        ->and($verifier->setupCommands([
            RuntimeRequirementVerifier::PHP_CLI_SQLSRV,
            RuntimeRequirementVerifier::PHP_CLI_MBSTRING,
        ]))->toBe([
            './scripts/setup-steps/16-sqlsrv-cli.sh',
            './scripts/setup-steps/17-mbstring-cli.sh',
        ]);
});

it('rejects undeclared runtime profiles instead of executing a manifest supplied command', function (): void {
    expect((new RuntimeRequirementVerifier)->unmet(['shell:curl | sudo sh']))
        ->toBe(['Unsupported runtime prerequisite [shell:curl | sudo sh].']);
});

/*
 * The tests below pin the binary the verifier actually probes.
 *
 * Each fixture gives the shared CLI a healthy answer and the caller-supplied
 * CLI a broken one. A verifier that reads the environment instead of its
 * argument therefore returns [] and the expectation fails -- which is the
 * defect shape from SB-Tape/blb-sbg#16, where an Extension resolved
 * SBG_AX_PHP_BINARY for its subprocess while the preflight only ever saw
 * BLB_SYSTEM_PHP_BINARY.
 */

it('probes the binary the caller supplied rather than the shared host CLI', function (): void {
    putenv('BLB_SYSTEM_PHP_BINARY=shared-php');
    Process::fake(function ($process) {
        return match ($process->command) {
            ['shared-php', '-m'] => Process::result("PDO\nmbstring\n"),
            ['ax-php', '-m'] => Process::result("PDO\n"),
            default => Process::result(exitCode: 1),
        };
    });

    expect((new RuntimeRequirementVerifier)->unmet([RuntimeRequirementVerifier::PHP_CLI_MBSTRING], 'ax-php'))
        ->toBe(['The PHP CLI at [ax-php] does not have mbstring available.']);
});

it('probes the supplied binary for the SQL Server profile as well', function (): void {
    putenv('BLB_SYSTEM_PHP_BINARY=shared-php');
    Process::fake(function ($process) {
        return match ($process->command) {
            ['shared-php', '-m'] => Process::result("PDO\nsqlsrv\npdo_sqlsrv\n"),
            ['shared-php', '-r', 'echo implode(",", PDO::getAvailableDrivers());'] => Process::result('sqlsrv'),
            ['ax-php', '-m'] => Process::result("PDO\n"),
            ['ax-php', '-r', 'echo implode(",", PDO::getAvailableDrivers());'] => Process::result('sqlite'),
            default => Process::result(exitCode: 1),
        };
    });

    expect((new RuntimeRequirementVerifier)->unmet([RuntimeRequirementVerifier::PHP_CLI_SQLSRV], 'ax-php'))
        ->toBe(['The PHP CLI at [ax-php] does not have sqlsrv and pdo_sqlsrv available.']);
});

it('keeps the shared-CLI wording when the caller names no binary', function (): void {
    putenv('BLB_SYSTEM_PHP_BINARY=shared-php');
    Process::fake(fn ($process) => match ($process->command) {
        ['shared-php', '-m'] => Process::result("PDO\n"),
        default => Process::result(exitCode: 1),
    });

    expect((new RuntimeRequirementVerifier)->unmet([RuntimeRequirementVerifier::PHP_CLI_MBSTRING]))
        ->toBe(['The system PHP CLI does not have mbstring available.']);
});
