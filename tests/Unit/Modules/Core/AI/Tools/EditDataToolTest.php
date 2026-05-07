<?php

use App\Modules\Core\AI\Tools\EditDataTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

const EDIT_NOT_ALLOWED = 'not allowed';
const EDIT_PROTECTED = 'protected';
const EDIT_SUCCESSFULLY = 'successfully';
const EDIT_TEST_TABLE_NAME = '_edit_tool_test';
const EDIT_TEST_TABLE_SCHEMA = 'CREATE TABLE _edit_tool_test (id INTEGER PRIMARY KEY, name TEXT)';
const EDIT_TEST_TABLE_DROP_SQL = 'DROP TABLE %s';
const EDIT_ROWS_AFFECTED = '1 row affected';

beforeEach(function () {
    $this->tool = new EditDataTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'edit_data',
            'admin.ai.tool.edit-data.execute',
            ['statement'],
            ['statement'],
        );
    });
});

describe('SQL validation', function () {
    it('rejects missing or empty statement', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('statement');
    });

    it('rejects DDL statements', function (string $statement, string $keyword) {
        $result = (string) $this->tool->execute(['statement' => $statement]);
        expect($result)->toContain($keyword)
            ->and($result)->toContain(EDIT_NOT_ALLOWED);
    })->with([
        ['DROP TABLE users', 'DROP'],
        ['ALTER TABLE users ADD COLUMN foo text', 'ALTER'],
        ['TRUNCATE TABLE users', 'TRUNCATE'],
        ['CREATE TABLE evil (id int)', 'CREATE'],
        ['GRANT ALL ON users TO public', 'GRANT'],
        ['REVOKE ALL ON users FROM public', 'REVOKE'],
    ]);

    it('rejects SELECT statements', function () {
        $result = (string) $this->tool->execute(['statement' => 'SELECT * FROM users']);
        expect($result)->toContain('query_data');
    });

    it('rejects multi-statement queries', function () {
        $result = (string) $this->tool->execute(['statement' => 'DELETE FROM jobs; DROP TABLE users']);
        expect($result)->toContain('not allowed');
    });

    it('rejects modifications to protected tables', function (string $table) {
        $result = (string) $this->tool->execute(['statement' => "DELETE FROM {$table} WHERE id = 1"]);
        expect($result)->toContain(EDIT_PROTECTED);
    })->with([
        'migrations',
        'base_database_tables',
        'base_database_seeders',
        'authz_roles',
        'authz_capabilities',
        'authz_role_capabilities',
        'authz_principal_capabilities',
        'authz_principal_roles',
    ]);
});

describe('statement execution', function () {
    it('executes an INSERT and reports affected rows', function () {
        // Create a scratch table for testing
        DB::statement(EDIT_TEST_TABLE_SCHEMA);

        $result = (string) $this->tool->execute([
            'statement' => "INSERT INTO _edit_tool_test (id, name) VALUES (1, 'test')",
        ]);

        expect($result)->toContain(EDIT_SUCCESSFULLY)
            ->and($result)->toContain(EDIT_ROWS_AFFECTED);

        DB::statement(sprintf(EDIT_TEST_TABLE_DROP_SQL, EDIT_TEST_TABLE_NAME));
    });

    it('executes an UPDATE and reports affected rows', function () {
        DB::statement(EDIT_TEST_TABLE_SCHEMA);
        DB::table('_edit_tool_test')->insert([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ]);

        $result = (string) $this->tool->execute([
            'statement' => "UPDATE _edit_tool_test SET name = 'updated' WHERE id = 1",
        ]);

        expect($result)->toContain(EDIT_SUCCESSFULLY)
            ->and($result)->toContain(EDIT_ROWS_AFFECTED);

        DB::statement(sprintf(EDIT_TEST_TABLE_DROP_SQL, EDIT_TEST_TABLE_NAME));
    });

    it('executes a DELETE and reports affected rows', function () {
        DB::statement(EDIT_TEST_TABLE_SCHEMA);
        DB::table('_edit_tool_test')->insert([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ]);

        $result = (string) $this->tool->execute([
            'statement' => 'DELETE FROM _edit_tool_test WHERE id = 1',
        ]);

        expect($result)->toContain(EDIT_SUCCESSFULLY)
            ->and($result)->toContain(EDIT_ROWS_AFFECTED);

        DB::statement(sprintf(EDIT_TEST_TABLE_DROP_SQL, EDIT_TEST_TABLE_NAME));
    });

    it('reports query errors gracefully', function () {
        $result = (string) $this->tool->execute([
            'statement' => 'UPDATE nonexistent_table_xyz SET x = 1 WHERE id = 1',
        ]);
        expect($result)->toContain('error');
    });

    it('strips trailing semicolons', function () {
        DB::statement(EDIT_TEST_TABLE_SCHEMA);

        $result = (string) $this->tool->execute([
            'statement' => "INSERT INTO _edit_tool_test (id, name) VALUES (1, 'test');",
        ]);
        expect($result)->toContain(EDIT_SUCCESSFULLY);

        DB::statement(sprintf(EDIT_TEST_TABLE_DROP_SQL, EDIT_TEST_TABLE_NAME));
    });

    it('uses plural for multiple rows', function () {
        DB::statement(EDIT_TEST_TABLE_SCHEMA);
        DB::table('_edit_tool_test')->insert([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
        ]);

        $result = (string) $this->tool->execute([
            'statement' => 'DELETE FROM _edit_tool_test WHERE id IN (1, 2)',
        ]);

        expect($result)->toContain('2 rows affected');

        DB::statement(sprintf(EDIT_TEST_TABLE_DROP_SQL, EDIT_TEST_TABLE_NAME));
    });
});
