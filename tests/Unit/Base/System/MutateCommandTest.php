<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $root = storage_path('framework/testing/mutate-cmd-'.bin2hex(random_bytes(4)));
    mkdir($root, 0777, true);
    $this->mutateCmdRoot = $root;
    $this->mutateCmdSource = $root.'/sample.php';
    $this->mutateCmdTest = $root.'/dummy-test.php';
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php'), $this->mutateCmdSource);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-dummy-test.php'), $this->mutateCmdTest);
    $this->mutateCmdOriginal = file_get_contents($this->mutateCmdSource);
});

afterEach(function (): void {
    $root = $this->mutateCmdRoot ?? null;
    if (! is_string($root) || $root === '' || ! is_dir($root)) {
        return;
    }
    foreach (glob($root.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root);
});

it('requires the --test option', function (): void {
    $this->artisan('blb:mutate', [
        'file' => $this->mutateCmdSource,
        'matcher' => 'GUARD_LINE_UNIQUE',
    ])->assertFailed();
});

it('prints Markdown evidence and restores the source', function (): void {
    $responses = [
        Process::result(output: "Tests:\t3 passed (11 assertions)\n"),
        Process::result(output: "Tests:\t1 failed, 2 passed (7 assertions)\n", exitCode: 1),
    ];
    Process::fake(function () use (&$responses) {
        return array_shift($responses) ?? Process::result(output: "Tests:\t0 passed (0 assertions)\n");
    });

    $pending = $this->withoutMockingConsoleOutput()->artisan('blb:mutate', [
        'file' => $this->mutateCmdSource,
        'matcher' => 'GUARD_LINE_UNIQUE',
        '--test' => $this->mutateCmdTest,
    ]);
    $output = Artisan::output();
    expect($pending)->toBe(0)
        ->and($output)->toContain('Mutation evidence')
        ->and($output)->toContain('Restored: yes')
        ->and($output)->toContain('Source restored');

    expect(file_get_contents($this->mutateCmdSource))->toBe($this->mutateCmdOriginal);
});

it('fails closed when the matcher is ambiguous', function (): void {
    Process::fake();

    $this->artisan('blb:mutate', [
        'file' => $this->mutateCmdSource,
        'matcher' => 'DUPLICATE_MARKER',
        '--test' => $this->mutateCmdTest,
    ])
        ->expectsOutputToContain('matched 2 lines')
        ->assertFailed();

    expect(file_get_contents($this->mutateCmdSource))->toBe($this->mutateCmdOriginal);
    Process::assertNothingRan();
});
