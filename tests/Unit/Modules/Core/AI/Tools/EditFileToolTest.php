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

it('creates files under a new parent directory', function () {
    $result = $this->tool->execute([
        'file_path' => EDIT_FILE_TOOL_TEST_FILE,
        'content' => 'hello world',
        'operation' => 'write',
    ]);

    expect((string) $result)->toContain('Created '.EDIT_FILE_TOOL_TEST_FILE)
        ->and(is_file(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBeTrue()
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBe('hello world');
});

it('returns an error when writing to a directory path', function () {
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_DIRECTORY_TARGET));

    $result = $this->tool->execute([
        'file_path' => EDIT_FILE_TOOL_DIRECTORY_TARGET,
        'content' => 'cannot write here',
        'operation' => 'write',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('Failed to write');
});

it('returns an error when appending to a directory path', function () {
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_DIRECTORY_TARGET));

    $result = $this->tool->execute([
        'file_path' => EDIT_FILE_TOOL_DIRECTORY_TARGET,
        'content' => 'cannot append here',
        'operation' => 'append',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('Failed to append');
});

it('performs targeted replacement edits', function () {
    File::put(base_path(EDIT_FILE_TOOL_TEST_FILE), "one\ntwo\nthree\n");

    $result = $this->tool->execute([
        'file_path' => EDIT_FILE_TOOL_TEST_FILE,
        'operation' => 'replace',
        'old_content' => "two\n",
        'new_content' => "TWO\n",
    ]);

    expect((string) $result)->toContain('targeted replacement')
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_TEST_FILE)))->toBe("one\nTWO\nthree\n");
});

it('blocks extension paths when target surface is core', function () {
    $result = $this->tool->execute([
        'file_path' => EDIT_FILE_TOOL_EXTENSION_DIRECTORY.'/sample.txt',
        'content' => 'wrong surface',
        'operation' => 'write',
        'target_surface' => 'core',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('extension');
});

it('writes inside selected extension surface', function () {
    $result = $this->tool->execute([
        'file_path' => 'sample.txt',
        'content' => 'extension owned',
        'operation' => 'write',
        'target_surface' => 'extension:edit-file-test',
    ]);

    expect((string) $result)->toContain('extensions/custom/edit-file-test/sample.txt')
        ->and(file_get_contents(base_path(EDIT_FILE_TOOL_EXTENSION_DIRECTORY.'/sample.txt')))->toBe('extension owned');
});
