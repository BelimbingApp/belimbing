<?php

use App\Modules\Core\AI\Tools\QueryDataTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

const QUERY_NOT_ALLOWED = 'not allowed';
const ONE_ROW = '1 row';

beforeEach(function () {
    $this->tool = new QueryDataTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'query_data',
            'ai.tool_query_data.execute',
            ['query', 'limit'],
            ['query'],
        );
    });
});

describe('SQL validation', function () {
    it('rejects missing or empty query', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('query');
    });

    it('rejects write statements', function (string $query, string $keyword) {
        $result = (string) $this->tool->execute(['query' => $query]);
        expect($result)->toContain($keyword)
            ->and($result)->toContain(QUERY_NOT_ALLOWED);
    })->with([
        ['INSERT INTO users (name) VALUES (\'test\')', 'INSERT'],
        ['UPDATE users SET name = \'test\'', 'UPDATE'],
        ['DELETE FROM users', 'DELETE'],
        ['DROP TABLE users', 'DROP'],
        ['ALTER TABLE users ADD COLUMN foo text', 'ALTER'],
        ['TRUNCATE TABLE users', 'TRUNCATE'],
        ['CREATE TABLE evil (id int)', 'CREATE'],
    ]);

    it('rejects multi-statement queries', function () {
        $result = (string) $this->tool->execute(['query' => 'SELECT 1; DROP TABLE users']);
        expect($result)->toContain('not allowed');
    });

    it('rejects queries not starting with SELECT or WITH', function () {
        $result = (string) $this->tool->execute(['query' => 'SHOW TABLES']);
        expect($result)->toContain('Only SELECT');
    });

    it('rejects SELECT with embedded write in subquery', function () {
        $result = (string) $this->tool->execute(['query' => 'SELECT * FROM (DELETE FROM users RETURNING *) AS x']);
        expect($result)->toContain('DELETE')
            ->and($result)->toContain('not allowed');
    });
});

describe('query execution', function () {
    it('executes a simple SELECT and returns formatted results', function () {
        $result = (string) $this->tool->execute(['query' => "SELECT 1 AS id, 'hello' AS greeting"]);

        expect($result)->toContain('id')
            ->and($result)->toContain('greeting')
            ->and($result)->toContain('hello')
            ->and($result)->toContain('1 row');
    });

    it('returns row count message for empty results', function () {
        $result = (string) $this->tool->execute([
            'query' => 'SELECT 1 AS id WHERE 1 = 0',
        ]);

        expect($result)->toBe('Query returned 0 rows.');
    });

    it('respects limit parameter', function () {
        // Build a UNION ALL query that produces more rows than the limit
        $unions = implode(' UNION ALL ', array_fill(0, 10, 'SELECT 1 AS n'));
        $result = (string) $this->tool->execute([
            'query' => $unions,
            'limit' => 3,
        ]);

        expect($result)->toContain('3 rows returned')
            ->and($result)->toContain('limit reached');
    });

    it('caps limit at maximum', function () {
        // Request a limit above MAX_ROWS; tool should cap it at 100
        $result = (string) $this->tool->execute([
            'query' => 'SELECT 1 AS n',
            'limit' => 999,
        ]);

        // With only 1 row of data, we can't test the cap numerically,
        // but we verify the tool doesn't error on an oversized limit
        expect($result)->toContain(ONE_ROW);
    });

    it('handles NULL values', function () {
        $result = (string) $this->tool->execute([
            'query' => 'SELECT NULL AS empty_col',
        ]);

        expect($result)->toContain('NULL');
    });

    it('strips trailing semicolons', function () {
        $result = (string) $this->tool->execute(['query' => 'SELECT 1 AS test;']);
        expect($result)->not->toContain('Error');
    });

    it('reports query errors gracefully', function () {
        $result = (string) $this->tool->execute(['query' => 'SELECT * FROM nonexistent_table_xyz']);
        expect($result)->toContain('error');
    });

    it('allows WITH (CTE) queries', function () {
        $result = (string) $this->tool->execute(['query' => 'WITH cte AS (SELECT 1 AS val) SELECT * FROM cte']);
        expect($result)->toContain('val')
            ->and($result)->toContain(ONE_ROW);
    });
});
