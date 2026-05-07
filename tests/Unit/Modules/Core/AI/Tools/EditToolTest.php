<?php

use App\Modules\Core\AI\Tools\EditTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

const EDIT_TOOL_TEST_DIRECTORY = 'tmp/testing/edit-tool';
const EDIT_TOOL_TEST_FILE = EDIT_TOOL_TEST_DIRECTORY.'/sample.txt';
const EDIT_TOOL_TEST_TABLE_NAME = '_edit_wrapper_tool_test';
const EDIT_TOOL_TEST_TABLE_SCHEMA = 'CREATE TABLE _edit_wrapper_tool_test (id INTEGER PRIMARY KEY, name TEXT)';

beforeEach(function (): void {
    $this->tool = new EditTool;
    File::deleteDirectory(base_path(EDIT_TOOL_TEST_DIRECTORY));
    File::ensureDirectoryExists(base_path(EDIT_TOOL_TEST_DIRECTORY));
});

afterEach(function (): void {
    File::deleteDirectory(base_path(EDIT_TOOL_TEST_DIRECTORY));
});

describe('tool metadata', function (): void {
    it('has the expected broad edit metadata', function (): void {
        $this->assertToolMetadata(
            $this->tool,
            'edit',
            'admin.ai.tool.edit.execute',
            ['target', 'file_path', 'statement'],
            [],
        );
    });
});

it('edits files through the broad edit capability', function (): void {
    $result = $this->tool->execute([
        'target' => 'file',
        'file_path' => EDIT_TOOL_TEST_FILE,
        'operation' => 'write',
        'content' => 'hello edit',
    ]);

    expect((string) $result)->toContain('Created '.EDIT_TOOL_TEST_FILE)
        ->and(file_get_contents(base_path(EDIT_TOOL_TEST_FILE)))->toBe('hello edit');
});

it('edits data through the broad edit capability', function (): void {
    DB::statement(EDIT_TOOL_TEST_TABLE_SCHEMA);

    $result = $this->tool->execute([
        'target' => 'data',
        'statement' => "INSERT INTO _edit_wrapper_tool_test (id, name) VALUES (1, 'test')",
    ]);

    expect((string) $result)->toContain('successfully')
        ->and(DB::table(EDIT_TOOL_TEST_TABLE_NAME)->where('id', 1)->value('name'))->toBe('test');

    DB::statement('DROP TABLE '.EDIT_TOOL_TEST_TABLE_NAME);
});
