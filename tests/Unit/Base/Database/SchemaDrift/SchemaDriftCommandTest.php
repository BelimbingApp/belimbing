<?php

use App\Base\Database\Contracts\SchemaDriftInspection;
use App\Base\Database\Services\SchemaDrift\SchemaDriftFinding;
use App\Base\Database\Services\SchemaDrift\SchemaDriftFindingKind;
use App\Base\Database\Services\SchemaDrift\SchemaDriftReport;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class);

it('returns clean only when the entire report was checked and matched', function (): void {
    $report = new SchemaDriftReport('testing', 'sqlite', ':memory:', 2, 1, [], []);
    $this->mock(SchemaDriftInspection::class, function (MockInterface $mock) use ($report): void {
        $mock->shouldReceive('inspect')->once()->with(null)->andReturn($report);
    });

    $this->artisan('blb:schema:drift')
        ->expectsOutput('SCHEMA_DRIFT connection="testing" driver="sqlite" database=":memory:" scope="tables,columns,indexes"')
        ->expectsOutput('SUMMARY migrations=2 tables=1 findings=0 unreadable=0')
        ->expectsOutput('RESULT CLEAN')
        ->assertExitCode(0);
});

it('returns one for confirmed drift', function (): void {
    $report = new SchemaDriftReport('testing', 'sqlite', ':memory:', 2, 1, [
        new SchemaDriftFinding(SchemaDriftFindingKind::MISSING_COLUMN, 'widgets', 'name', 'widgets.php', 12),
    ], []);
    $this->mock(SchemaDriftInspection::class, function (MockInterface $mock) use ($report): void {
        $mock->shouldReceive('inspect')->once()->with(null)->andReturn($report);
    });

    $this->artisan('blb:schema:drift')
        ->expectsOutput('DRIFT kind=missing_column table="widgets" object="name" source="widgets.php:12"')
        ->expectsOutput('RESULT DRIFT')
        ->assertExitCode(1);
});

it('returns two instead of guessing when source analysis is incomplete', function (): void {
    $report = new SchemaDriftReport('testing', 'sqlite', ':memory:', 2, 1, [], [[
        'migration' => 'widgets.php',
        'line' => 14,
        'reason' => 'Runtime-dependent table name.',
    ]]);
    $this->mock(SchemaDriftInspection::class, function (MockInterface $mock) use ($report): void {
        $mock->shouldReceive('inspect')->once()->with(null)->andReturn($report);
    });

    $this->artisan('blb:schema:drift')
        ->expectsOutput('UNREADABLE source="widgets.php:14" reason="Runtime-dependent table name."')
        ->expectsOutput('RESULT INCOMPLETE')
        ->assertExitCode(2);
});

it('inspects the explicitly selected database connection', function (): void {
    $report = new SchemaDriftReport('reporting', 'sqlite', ':memory:', 2, 1, [], []);
    $this->mock(SchemaDriftInspection::class, function (MockInterface $mock) use ($report): void {
        $mock->shouldReceive('inspect')->once()->with('reporting')->andReturn($report);
    });

    $this->artisan('blb:schema:drift', ['--database' => 'reporting'])
        ->expectsOutput('SCHEMA_DRIFT connection="reporting" driver="sqlite" database=":memory:" scope="tables,columns,indexes"')
        ->assertExitCode(0);
});
