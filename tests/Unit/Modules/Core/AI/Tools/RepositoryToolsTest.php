<?php

use App\Modules\Core\AI\Tools\ReadTool;
use App\Modules\Core\AI\Tools\SearchTool;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

const REPO_TOOL_TEST_DIR = 'tmp/testing/repo-tools';
const REPO_TOOL_EXTENSION_DIR = 'extensions/custom/acme-test';
const REPO_TOOL_CHUNKED_FILE = REPO_TOOL_TEST_DIR.'/chunked.txt';

beforeEach(function (): void {
    $this->repoToolNeedle = 'repo_tool_'.str()->random(16);
    File::deleteDirectory(base_path(REPO_TOOL_TEST_DIR));
    File::deleteDirectory(base_path(REPO_TOOL_EXTENSION_DIR));
    File::ensureDirectoryExists(base_path(REPO_TOOL_TEST_DIR));
    File::ensureDirectoryExists(base_path(REPO_TOOL_EXTENSION_DIR));
    File::put(base_path(REPO_TOOL_TEST_DIR.'/sample.txt'), "alpha\n{$this->repoToolNeedle}\n");
    File::put(base_path(REPO_TOOL_EXTENSION_DIR.'/extension.txt'), "extension alpha\n");
});

afterEach(function (): void {
    File::deleteDirectory(base_path(REPO_TOOL_TEST_DIR));
    File::deleteDirectory(base_path(REPO_TOOL_EXTENSION_DIR));
    File::delete(base_path('storage/app/ai/wire-logs/run_test.jsonl'));
});

it('reads files from the core surface', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_TEST_DIR.'/sample.txt',
        'target_surface' => 'core',
    ]);

    expect((string) $result)->toContain('alpha')
        ->and((string) $result)->toContain(REPO_TOOL_TEST_DIR.'/sample.txt');
});

it('returns bounded file chunks with continuation offsets', function (): void {
    File::put(base_path(REPO_TOOL_CHUNKED_FILE), "one\ntwo\nthree\n");

    $first = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_CHUNKED_FILE,
        'target_surface' => 'core',
        'limit' => 2,
    ]);
    $firstPayload = json_decode((string) $first, true, flags: JSON_THROW_ON_ERROR);

    $second = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_CHUNKED_FILE,
        'target_surface' => 'core',
        'offset' => $firstPayload['next_offset'],
        'limit' => 2,
    ]);
    $secondPayload = json_decode((string) $second, true, flags: JSON_THROW_ON_ERROR);

    expect($firstPayload['content'])->toBe("one\ntwo\n")
        ->and($firstPayload['has_more'])->toBeTrue()
        ->and($firstPayload['next_offset'])->toBe(2)
        ->and($secondPayload['content'])->toBe("three\n")
        ->and($secondPayload['has_more'])->toBeFalse();
});

it('blocks extension paths when target surface is core', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_EXTENSION_DIR.'/extension.txt',
        'target_surface' => 'core',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('extension');
});

it('reads files from an extension surface', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => 'extension.txt',
        'target_surface' => 'extension:acme-test',
    ]);

    expect((string) $result)->toContain('extension alpha')
        ->and((string) $result)->toContain('extensions/custom/acme-test/extension.txt');
});

it('searches file contents within a surface', function (): void {
    $result = (new SearchTool)->execute([
        'query' => $this->repoToolNeedle,
        'mode' => 'content',
        'target_surface' => 'core',
        'max_results' => 10,
    ]);

    expect((string) $result)->toContain(REPO_TOOL_TEST_DIR.'/sample.txt')
        ->and((string) $result)->toContain($this->repoToolNeedle);
});

it('excludes AI wire logs from repository search', function (): void {
    File::ensureDirectoryExists(base_path('storage/app/ai/wire-logs'));
    File::put(base_path('storage/app/ai/wire-logs/run_test.jsonl'), $this->repoToolNeedle);

    $result = (new SearchTool)->execute([
        'query' => $this->repoToolNeedle,
        'mode' => 'content',
        'target_surface' => 'core',
        'max_results' => 10,
    ]);

    expect((string) $result)->toContain(REPO_TOOL_TEST_DIR.'/sample.txt')
        ->and((string) $result)->not->toContain('storage/app/ai/wire-logs/run_test.jsonl');
});

it('blocks reading AI wire logs', function (): void {
    File::ensureDirectoryExists(base_path('storage/app/ai/wire-logs'));
    File::put(base_path('storage/app/ai/wire-logs/run_test.jsonl'), $this->repoToolNeedle);

    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => 'storage/app/ai/wire-logs/run_test.jsonl',
        'target_surface' => 'core',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('storage/app/ai/wire-logs/');
});
