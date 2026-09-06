<?php

use App\Base\System\Exceptions\GuardMutationException;
use App\Base\System\Services\GuardMutator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $root = storage_path('framework/testing/guard-mutator-'.bin2hex(random_bytes(4)));
    mkdir($root, 0777, true);
    $this->guardMutatorRoot = $root;
    $this->guardMutatorSource = $root.'/sample.php';
    $this->guardMutatorTest = $root.'/dummy-test.php';
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php'), $this->guardMutatorSource);
    copy(base_path('tests/Unit/Base/System/Fixtures/guard-mutator-dummy-test.php'), $this->guardMutatorTest);
    $this->guardMutatorOriginal = file_get_contents($this->guardMutatorSource);
});

afterEach(function (): void {
    $root = $this->guardMutatorRoot ?? null;
    if (! is_string($root) || $root === '' || ! is_dir($root)) {
        return;
    }
    foreach (glob($root.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root);
});

it('refuses when the pattern matches zero lines', function (): void {
    $mutator = new GuardMutator(fn (): array => [
        'exit_code' => 0,
        'passed' => 1,
        'failed' => 0,
        'assertions' => 1,
        'output' => '',
    ]);

    expect(fn () => $mutator->run($this->guardMutatorSource, 'NO_SUCH_GUARD', $this->guardMutatorTest))
        ->toThrow(GuardMutationException::class, 'matched zero lines');

    expect(file_get_contents($this->guardMutatorSource))->toBe($this->guardMutatorOriginal);
});

it('refuses when the pattern matches many lines', function (): void {
    $mutator = new GuardMutator(fn (): array => [
        'exit_code' => 0,
        'passed' => 1,
        'failed' => 0,
        'assertions' => 1,
        'output' => '',
    ]);

    expect(fn () => $mutator->run($this->guardMutatorSource, 'DUPLICATE_MARKER', $this->guardMutatorTest))
        ->toThrow(GuardMutationException::class, 'matched 2 lines');

    expect(file_get_contents($this->guardMutatorSource))->toBe($this->guardMutatorOriginal);
});

it('restores the source when the after run reports failure', function (): void {
    $calls = 0;
    $mutator = new GuardMutator(function () use (&$calls): array {
        $calls++;

        if ($calls === 1) {
            return [
                'exit_code' => 0,
                'passed' => 3,
                'failed' => 0,
                'assertions' => 11,
                'output' => "Tests:\t3 passed (11 assertions)\n",
            ];
        }

        return [
            'exit_code' => 1,
            'passed' => 2,
            'failed' => 1,
            'assertions' => 7,
            'output' => "Tests:\t1 failed, 2 passed (7 assertions)\n",
        ];
    });

    $result = $mutator->run($this->guardMutatorSource, 'GUARD_LINE_UNIQUE', $this->guardMutatorTest);

    expect($calls)->toBe(2)
        ->and(file_get_contents($this->guardMutatorSource))->toBe($this->guardMutatorOriginal)
        ->and($result['restored'])->toBeTrue()
        ->and($result['before']['passed'])->toBe(3)
        ->and($result['after']['failed'])->toBe(1)
        ->and($result['removed_line_number'])->toBe(12);
});

it('restores the source when the test runner throws after mutation', function (): void {
    $calls = 0;
    $mutator = new GuardMutator(function () use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            return [
                'exit_code' => 0,
                'passed' => 1,
                'failed' => 0,
                'assertions' => 1,
                'output' => '',
            ];
        }

        throw new RuntimeException('runner exploded');
    });

    expect(fn () => $mutator->run($this->guardMutatorSource, 'GUARD_LINE_UNIQUE', $this->guardMutatorTest))
        ->toThrow(RuntimeException::class, 'runner exploded');

    expect(file_get_contents($this->guardMutatorSource))->toBe($this->guardMutatorOriginal);
});

it('prints a stable Markdown evidence block', function (): void {
    $calls = 0;
    $mutator = new GuardMutator(function () use (&$calls): array {
        $calls++;

        return $calls === 1
            ? [
                'exit_code' => 0,
                'passed' => 6,
                'failed' => 0,
                'assertions' => 25,
                'output' => '',
            ]
            : [
                'exit_code' => 1,
                'passed' => 5,
                'failed' => 1,
                'assertions' => 18,
                'output' => '',
            ];
    });

    $result = $mutator->run($this->guardMutatorSource, 'GUARD_LINE_UNIQUE', $this->guardMutatorTest);

    $expected = implode("\n", [
        '**Mutation evidence**',
        '',
        '- File: `'.ltrim(str_replace(base_path(), '', $this->guardMutatorSource), '/').'`',
        '- Matcher: `GUARD_LINE_UNIQUE`',
        '- Removed line 12: `    // GUARD_LINE_UNIQUE`',
        '- Before: 6 passed / 25 assertions',
        '- After: 1 failed, 5 passed / 18 assertions',
        '- Restored: yes',
        '',
    ]);

    // Normalize Windows separators if any.
    $markdown = str_replace('\\', '/', $result['markdown']);
    $expected = str_replace('\\', '/', $expected);

    expect($markdown)->toBe($expected);
});

it('parses Pest summary lines for counts', function (): void {
    $mutator = new GuardMutator(fn (): array => ['exit_code' => 0, 'output' => '']);

    expect($mutator->parsePestSummary("Tests:\t1 failed, 2 passed (7 assertions)\n"))
        ->toBe([
            'passed' => 2,
            'failed' => 1,
            'assertions' => 7,
        ]);
});

it('removes a line by number and refuses out-of-range numbers', function (): void {
    $mutator = new GuardMutator(fn (): array => [
        'exit_code' => 0,
        'passed' => 1,
        'failed' => 0,
        'assertions' => 1,
        'output' => '',
    ]);

    $result = $mutator->run($this->guardMutatorSource, '12', $this->guardMutatorTest);
    expect($result['removed_line_number'])->toBe(12)
        ->and(file_get_contents($this->guardMutatorSource))->toBe($this->guardMutatorOriginal);

    expect(fn () => $mutator->run($this->guardMutatorSource, '999', $this->guardMutatorTest))
        ->toThrow(GuardMutationException::class, 'out of range');
});

it('accepts a repo-relative source path', function (): void {
    $relative = 'tests/Unit/Base/System/Fixtures/guard-mutator-sample.php';
    $absolute = base_path($relative);
    $original = file_get_contents($absolute);
    $calls = 0;
    $mutator = new GuardMutator(function () use (&$calls): array {
        $calls++;

        return [
            'exit_code' => $calls === 1 ? 0 : 1,
            'passed' => $calls === 1 ? 1 : 0,
            'failed' => $calls === 1 ? 0 : 1,
            'assertions' => 1,
            'output' => '',
        ];
    });

    try {
        $result = $mutator->run($relative, 'GUARD_LINE_UNIQUE', $this->guardMutatorTest);
        expect($result['restored'])->toBeTrue()
            ->and($result['markdown'])->toContain('tests/Unit/Base/System/Fixtures/guard-mutator-sample.php');
    } finally {
        file_put_contents($absolute, $original);
    }
});

it('refuses a missing source file', function (): void {
    $mutator = new GuardMutator(fn (): array => ['exit_code' => 0, 'output' => '']);

    expect(fn () => $mutator->run($this->guardMutatorRoot.'/missing.php', 'x', $this->guardMutatorTest))
        ->toThrow(GuardMutationException::class, 'does not exist');
});
