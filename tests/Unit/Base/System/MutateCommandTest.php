<?php

use App\Base\System\Services\GuardMutationSuggester;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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

    $this->mutateSuggestSource = $root.'/app/Base/System/Fixtures/SuggestedGuardSubject.php';
    $this->mutateSuggestTest = $root.'/tests/Unit/Base/System/SuggestedGuardSubjectTest.php';
    File::ensureDirectoryExists(dirname($this->mutateSuggestSource));
    File::ensureDirectoryExists(dirname($this->mutateSuggestTest));
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-suggester-source.php'), $this->mutateSuggestSource);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-suggester-test.php'), $this->mutateSuggestTest);

    $this->mutateOtherSource = $root.'/app/Base/System/Fixtures/SuggestedOtherGuards.php';
    $this->mutateOtherTest = $root.'/tests/Unit/Base/System/SuggestedOtherGuardsTest.php';
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-suggester-other-source.php'), $this->mutateOtherSource);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-suggester-other-test.php'), $this->mutateOtherTest);
});

afterEach(function (): void {
    putenv('CLAIM_AGENT');

    $root = $this->mutateCmdRoot ?? null;
    if (! is_string($root) || $root === '' || ! is_dir($root)) {
        return;
    }
    File::deleteDirectory($root);
});

it('suggests referenced guard lines as batch json without changing fixtures', function (): void {
    app()->instance(
        GuardMutationSuggester::class,
        new GuardMutationSuggester($this->mutateCmdRoot),
    );
    $sourceBefore = file_get_contents($this->mutateSuggestSource);
    $testBefore = file_get_contents($this->mutateSuggestTest);
    Process::fake();

    $pending = $this->withoutMockingConsoleOutput()->artisan('blb:mutate', [
        '--suggest' => $this->mutateSuggestTest,
    ]);
    $suggestions = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($pending)->toBe(0)
        ->and($suggestions)->toBe([
            [
                'file' => 'app/Base/System/Fixtures/SuggestedGuardSubject.php',
                'pattern' => '12',
                'test' => 'tests/Unit/Base/System/SuggestedGuardSubjectTest.php',
            ],
            [
                'file' => 'app/Base/System/Fixtures/SuggestedGuardSubject.php',
                'pattern' => '15',
                'test' => 'tests/Unit/Base/System/SuggestedGuardSubjectTest.php',
            ],
        ])
        ->and(file_get_contents($this->mutateSuggestSource))->toBe($sourceBefore)
        ->and(file_get_contents($this->mutateSuggestTest))->toBe($testBefore);

    Process::assertNothingRan();
});

it('finds abort and conditional-return guards referenced by qualified class name', function (): void {
    $sourceBefore = file_get_contents($this->mutateOtherSource);

    expect((new GuardMutationSuggester($this->mutateCmdRoot))->suggest($this->mutateOtherTest))->toBe([
        [
            'file' => 'app/Base/System/Fixtures/SuggestedOtherGuards.php',
            'pattern' => '9',
            'test' => 'tests/Unit/Base/System/SuggestedOtherGuardsTest.php',
        ],
        [
            'file' => 'app/Base/System/Fixtures/SuggestedOtherGuards.php',
            'pattern' => '12',
            'test' => 'tests/Unit/Base/System/SuggestedOtherGuardsTest.php',
        ],
    ])->and(file_get_contents($this->mutateOtherSource))->toBe($sourceBefore);
});

it('keeps suggestion mode separate from mutation execution', function (): void {
    app()->instance(
        GuardMutationSuggester::class,
        new GuardMutationSuggester($this->mutateCmdRoot),
    );
    $sourceBefore = file_get_contents($this->mutateSuggestSource);
    Process::fake();

    $this->artisan('blb:mutate', [
        '--suggest' => $this->mutateSuggestTest,
        '--batch' => '[]',
    ])
        ->expectsOutputToContain('cannot be combined')
        ->assertFailed();

    expect(file_get_contents($this->mutateSuggestSource))->toBe($sourceBefore);
    Process::assertNothingRan();
});

it('requires a batch when review evidence is requested', function (): void {
    $this->artisan('blb:mutate', [
        'file' => $this->mutateCmdSource,
        'matcher' => 'GUARD_LINE_UNIQUE',
        '--test' => $this->mutateCmdTest,
        '--evidence' => '42',
    ])
        ->expectsOutputToContain('requires --batch')
        ->assertFailed();
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

it('runs a batch with restore between entries and prints a Markdown table', function (): void {
    $root = $this->mutateCmdRoot;
    $sourceA = $root.'/sample-a.php';
    $sourceB = $root.'/sample-b.php';
    $testA = $root.'/dummy-a.php';
    $testB = $root.'/dummy-b.php';
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php'), $sourceA);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php'), $sourceB);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-dummy-test.php'), $testA);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-dummy-test.php'), $testB);
    $originalA = file_get_contents($sourceA);
    $originalB = file_get_contents($sourceB);

    Process::fake(function () use ($sourceA, $sourceB, $originalA, $originalB) {
        $allRestored = file_get_contents($sourceA) === $originalA
            && file_get_contents($sourceB) === $originalB;

        if ($allRestored) {
            return Process::result(output: "Tests:\t3 passed (11 assertions)\n");
        }

        return Process::result(output: "Tests:\t1 failed, 2 passed (7 assertions)\n", exitCode: 1);
    });

    $batch = json_encode([
        ['file' => $sourceA, 'pattern' => 'GUARD_LINE_UNIQUE', 'test' => $testA],
        ['file' => $sourceB, 'pattern' => 'GUARD_LINE_UNIQUE', 'test' => $testB],
    ], JSON_THROW_ON_ERROR);

    $pending = $this->withoutMockingConsoleOutput()->artisan('blb:mutate', [
        '--batch' => $batch,
    ]);
    $output = Artisan::output();

    expect($pending)->toBe(0)
        ->and($output)->toContain('Mutation evidence (batch)')
        ->and($output)->toContain('| Mutation | Before | After | Restored |')
        ->and(substr_count($output, '| yes |'))->toBe(2)
        ->and($output)->toContain('3 passed / 11 assertions')
        ->and($output)->toContain('1 failed, 2 passed / 7 assertions');

    expect(file_get_contents($sourceA))->toBe($originalA)
        ->and(file_get_contents($sourceB))->toBe($originalB);

    // Both rows show the restored before-count; without per-entry restore the second
    // before would see the first mutation and print the failed summary instead.
    $rows = array_values(array_filter(
        explode("\n", $output),
        fn (string $line): bool => str_starts_with($line, '| `') && str_contains($line, 'GUARD_LINE_UNIQUE'),
    ));
    expect($rows)->toHaveCount(2);
    expect($rows[0])->toContain('3 passed / 11 assertions')
        ->and($rows[0])->toContain('1 failed, 2 passed / 7 assertions');
    expect($rows[1])->toContain('3 passed / 11 assertions')
        ->and($rows[1])->toContain('1 failed, 2 passed / 7 assertions');
});

it('prefixes batch output with exact-head review markers', function (): void {
    $head = str_repeat('a', 40);
    putenv('CLAIM_AGENT=desktop-sol');

    Process::fake(function ($process) use ($head) {
        $command = $process->command;

        return match ($command) {
            ['git', 'status', '--porcelain', '--untracked-files=all'] => Process::result(),
            ['git', 'rev-parse', 'HEAD'], ['git', 'rev-parse', 'FETCH_HEAD'] => Process::result(output: $head."\n"),
            ['git', 'fetch', '--quiet', '--no-tags', 'origin', 'refs/pull/42/head'] => Process::result(),
            default => str_contains((string) end($command), 'dummy-test.php')
                ? Process::result(output: "Tests:\t3 passed (11 assertions)\n")
                : Process::result(exitCode: 1, errorOutput: 'unexpected command'),
        };
    });

    $batch = json_encode([
        ['file' => $this->mutateCmdSource, 'pattern' => 'GUARD_LINE_UNIQUE', 'test' => $this->mutateCmdTest],
    ], JSON_THROW_ON_ERROR);

    $pending = $this->withoutMockingConsoleOutput()->artisan('blb:mutate', [
        '--batch' => $batch,
        '--evidence' => '42',
    ]);
    $output = Artisan::output();

    expect($pending)->toBe(0)
        ->and($output)->toStartWith("**From:** desktop-sol\n\n**HEAD reviewed:** {$head}\n\n**Verdict:** <accept|changes required>")
        ->and($output)->toContain('| Mutation | Before | After | Restored |');
});

it('refuses an ambiguous batch before mutating any fixture', function (): void {
    $root = $this->mutateCmdRoot;
    $sourceA = $root.'/batch-ok.php';
    $sourceB = $root.'/batch-ambiguous.php';
    $testA = $root.'/batch-ok-test.php';
    $testB = $root.'/batch-ambiguous-test.php';
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php'), $sourceA);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php'), $sourceB);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-dummy-test.php'), $testA);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-dummy-test.php'), $testB);
    $originalA = file_get_contents($sourceA);
    $originalB = file_get_contents($sourceB);

    Process::fake();

    $batch = json_encode([
        ['file' => $sourceA, 'pattern' => 'GUARD_LINE_UNIQUE', 'test' => $testA],
        ['file' => $sourceB, 'pattern' => 'DUPLICATE_MARKER', 'test' => $testB],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('blb:mutate', [
        '--batch' => $batch,
    ])
        ->expectsOutputToContain('matched 2 lines')
        ->assertFailed();

    expect(file_get_contents($sourceA))->toBe($originalA)
        ->and(file_get_contents($sourceB))->toBe($originalB);
    Process::assertNothingRan();
});
