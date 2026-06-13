<?php

use App\Modules\Core\AI\Tools\EditFileTool;
use Illuminate\Support\Facades\File;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const EDIT_FILE_TOOL_TEST_DIRECTORY = 'tmp/testing/edit-file-tool';
const EDIT_FILE_TOOL_TEST_FILE = EDIT_FILE_TOOL_TEST_DIRECTORY.'/sample.txt';
const EDIT_FILE_TOOL_DIRECTORY_TARGET = EDIT_FILE_TOOL_TEST_DIRECTORY.'/existing-directory';
const EDIT_FILE_TOOL_EXTENSION_DIRECTORY = 'extensions/custom/edit-file-test';

beforeEach(function () {
    $this->tool = new EditFileTool;
    File::deleteDirectory(base_path(EDIT_FILE_TOOL_TEST_DIRECTORY));
    File::deleteDirectory(base_path(EDIT_FILE_TOOL_EXTENSION_DIRECTORY));
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_TEST_DIRECTORY));
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_EXTENSION_DIRECTORY));
});

afterEach(function () {
    File::deleteDirectory(base_path(EDIT_FILE_TOOL_TEST_DIRECTORY));
    File::deleteDirectory(base_path(EDIT_FILE_TOOL_EXTENSION_DIRECTORY));
});

function decodeEditFileToolResult(mixed $result): array
{
    $decoded = json_decode((string) $result, true);

    expect($decoded)->toBeArray();

    return $decoded;
}

function executeEditFileTool(EditFileTool $tool, array $arguments): array|string
{
    $result = $tool->execute($arguments);

    if (str_starts_with((string) $result, 'Error')) {
        return (string) $result;
    }

    return decodeEditFileToolResult($result);
}

it('creates files under a new parent directory', function () {
    $payload = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_TEST_FILE,
        'content' => 'hello world',
        'operation' => 'write',
    ]);

    expect($payload['summary'])->toContain('Created '.EDIT_FILE_TOOL_TEST_FILE)
        ->and($payload['target_surface'])->toBe('core')
        ->and($payload['file_path'])->toBe(EDIT_FILE_TOOL_TEST_FILE)
        ->and($payload['operation'])->toBe('write')
        ->and($payload['created'])->toBeTrue()
        ->and($payload['changed'])->toBeTrue()
        ->and($payload['bytes_written'])->toBe(11)
        ->and($payload['diff_preview'])->toContain('+++ after/'.EDIT_FILE_TOOL_TEST_FILE)
        ->and($payload['diff_preview'])->toContain('+hello world')
        ->and($payload['diff_truncated'])->toBeFalse()
        ->and(is_file(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBeTrue()
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBe('hello world');
});

it('returns structured append metadata with appended content preview', function () {
    File::put(base_path(EDIT_FILE_TOOL_TEST_FILE), "one\n");

    $payload = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_TEST_FILE,
        'content' => 'two',
        'operation' => 'append',
    ]);

    expect($payload['summary'])->toContain('Appended 3 bytes')
        ->and($payload['operation'])->toBe('append')
        ->and($payload['created'])->toBeFalse()
        ->and($payload['changed'])->toBeTrue()
        ->and($payload['diff_preview'])->toContain('@@ added content @@')
        ->and($payload['diff_preview'])->toContain('+two')
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBe("one\ntwo");
});

it('returns an error when writing to a directory path', function () {
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_DIRECTORY_TARGET));

    $result = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_DIRECTORY_TARGET,
        'content' => 'cannot write here',
        'operation' => 'write',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('Failed to write');
});

it('returns an error when appending to a directory path', function () {
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_DIRECTORY_TARGET));

    $result = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_DIRECTORY_TARGET,
        'content' => 'cannot append here',
        'operation' => 'append',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('Failed to append');
});

it('performs targeted replacement edits', function () {
    File::put(base_path(EDIT_FILE_TOOL_TEST_FILE), "one\ntwo\nthree\n");

    $payload = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_TEST_FILE,
        'operation' => 'replace',
        'old_content' => "two\n",
        'new_content' => "TWO\n",
    ]);

    expect($payload['summary'])->toContain('targeted replacement')
        ->and($payload['operation'])->toBe('replace')
        ->and($payload['created'])->toBeFalse()
        ->and($payload['changed'])->toBeTrue()
        ->and($payload['replacement_count'])->toBe(1)
        ->and($payload['diff_preview'])->toContain('@@ targeted replacement @@')
        ->and($payload['diff_preview'])->toContain('-two')
        ->and($payload['diff_preview'])->toContain('+TWO')
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBe("one\nTWO\nthree\n");
});

it('caps large edit previews', function () {
    $content = str_repeat('large-preview-line'.PHP_EOL, 1200);

    $payload = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_TEST_FILE,
        'content' => $content,
        'operation' => 'write',
    ]);

    expect($payload['diff_truncated'])->toBeTrue()
        ->and(strlen($payload['diff_preview']))->toBeGreaterThan(12000)
        ->and(strlen($payload['diff_preview']))->toBeLessThan(12100)
        ->and($payload['diff_preview'])->toContain('diff preview truncated');
});

it('blocks extension paths when target surface is core', function () {
    $result = executeEditFileTool($this->tool, [
        'file_path' => EDIT_FILE_TOOL_EXTENSION_DIRECTORY.'/sample.txt',
        'content' => 'wrong surface',
        'operation' => 'write',
        'target_surface' => 'core',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('extension');
});

it('writes inside selected extension surface', function () {
    $payload = executeEditFileTool($this->tool, [
        'file_path' => 'sample.txt',
        'content' => 'extension owned',
        'operation' => 'write',
        'target_surface' => 'extension:edit-file-test',
    ]);

    expect($payload['target_surface'])->toBe('extension:edit-file-test')
        ->and($payload['file_path'])->toBe('extensions/custom/edit-file-test/sample.txt')
        ->and($payload['summary'])->toContain('extensions/custom/edit-file-test/sample.txt')
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_EXTENSION_DIRECTORY.'/sample.txt')))->toBe('extension owned');
});
