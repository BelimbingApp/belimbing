<?php

use App\Modules\Core\AI\Tools\EditFileTool;
use Illuminate\Support\Facades\File;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

const EDIT_FILE_TOOL_TEST_DIRECTORY = 'storage/app/testing/edit-file-tool';
const EDIT_FILE_TOOL_TEST_FILE = EDIT_FILE_TOOL_TEST_DIRECTORY.'/sample.txt';
const EDIT_FILE_TOOL_DIRECTORY_TARGET = EDIT_FILE_TOOL_TEST_DIRECTORY.'/existing-directory';

beforeEach(function () {
    $this->tool = new EditFileTool;
    File::deleteDirectory(base_path(EDIT_FILE_TOOL_TEST_DIRECTORY));
    File::ensureDirectoryExists(base_path(EDIT_FILE_TOOL_TEST_DIRECTORY));
});

afterEach(function () {
    File::deleteDirectory(base_path(EDIT_FILE_TOOL_TEST_DIRECTORY));
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
