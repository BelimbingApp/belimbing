<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Base\Support\Str as BlbStr;
use Illuminate\Support\Facades\DB;

/**
 * Read-only database query tool for Agents.
 *
 * Allows a agent to execute SELECT queries against the application database
 * to answer data questions (e.g., "How many employees are active?").
 *
 * Safety: Only SELECT statements are allowed. Write operations (INSERT,
 * UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, GRANT, REVOKE) are
 * rejected at the SQL parsing level. A statement timeout and row count
 * cap provide additional protection.
 *
 * Gated by `ai.tool_query_data.execute` authz capability.
 */
class QueryDataTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const MAX_ROWS = 100;

    private const DEFAULT_LIMIT = 50;

    /**
     * SQL keywords that indicate a write operation.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'TRUNCATE',
        'CREATE',
        'GRANT',
        'REVOKE',
        'REPLACE',
        'RENAME',
        'LOCK',
        'UNLOCK',
        'CALL',
        'EXEC',
        'EXECUTE',
        'COPY',
        'VACUUM',
        'REINDEX',
    ];

    public function name(): string
    {
        return 'query_data';
    }

    public function description(): string
    {
        return 'Execute a read-only SQL query against the BLB database and return results. '
            .'Use this to answer data questions like "How many employees are active?", '
            .'"Show recent orders", or "List all companies". Only SELECT queries are allowed. '
            .'Results are returned as a formatted table. Maximum '.self::MAX_ROWS.' rows.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'query',
                'The SQL SELECT query to execute. '
                    .'Only SELECT statements are allowed — no INSERT, UPDATE, DELETE, DROP, etc. '
                    .'Use standard SQL compatible with PostgreSQL. '
                    .'Examples: "SELECT count(*) FROM employees WHERE status = \'active\'", '
                    .'"SELECT id, full_name, designation FROM employees LIMIT 10".'
            )->required()
            ->integer(
                'limit',
                'Maximum number of rows to return (1–'.self::MAX_ROWS.', default '.self::DEFAULT_LIMIT.'). '
                    .'Overrides any LIMIT clause in the query.',
                min: 1,
                max: self::MAX_ROWS,
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DATA;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::INTERNAL;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_query_data.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Query Data',
            'summary' => 'Read data from BLB using safe, read-only SQL.',
            'explanation' => 'Executes SELECT queries against the application database to answer data questions. '
                .'Only read-only operations are allowed — write statements (INSERT, UPDATE, DELETE, DROP, etc.) '
                .'are rejected at the SQL parsing level. Results are returned as formatted tables. '
                .'This tool cannot modify data or schema.',
            'setup_requirements' => [
                'No external API key required',
                'Database connection must be available',
            ],
            'test_examples' => [
                [
                    'label' => 'Count employees',
                    'input' => ['query' => 'SELECT count(*) AS total FROM employees'],
                ],
                [
                    'label' => 'List tables',
                    'input' => ['query' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' LIMIT 10"],
                ],
            ],
            'health_checks' => [
                'Database reachable',
                'Read-only SQL validator active',
            ],
            'limits' => [
                'Maximum 100 rows per query',
                '10-second statement timeout',
                'SELECT and WITH statements only',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $query = $this->requireString($arguments, 'query');

        // Remove trailing semicolons (prevents multi-statement injection)
        $query = rtrim($query, ';');

        $this->validateQuery($query);

        $limit = $this->optionalInt($arguments, 'limit', self::DEFAULT_LIMIT, min: 1, max: self::MAX_ROWS);

        return $this->runValidatedQuery($query, $limit);
    }

    /**
     * Validate that the query is a safe SELECT statement.
     *
     * Checks are ordered to produce the most specific error message:
     * forbidden keywords first (names the offending operation), then
     * structural checks (multi-statement, must start with SELECT/WITH).
     *
     * @throws ToolArgumentException If the query is not a safe SELECT
     */
    private function validateQuery(string $query): void
    {
        // Reject semicolons within the query (multi-statement)
        if (str_contains($query, ';')) {
            throw new ToolArgumentException('Multiple statements are not allowed.');
        }

        // Scan for forbidden keywords at word boundaries
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.$keyword.'\b/i', $query)) {
                throw new ToolArgumentException(
                    $keyword.' operations are not allowed. Only SELECT queries are permitted.'
                );
            }
        }

        // Must start with SELECT or WITH (for CTEs)
        $normalised = strtoupper(ltrim($query));

        if (! str_starts_with($normalised, 'SELECT') && ! str_starts_with($normalised, 'WITH')) {
            throw new ToolArgumentException(
                'Only SELECT queries are allowed. Your query must start with SELECT or WITH.'
            );
        }
    }

    /**
     * Apply a row limit to the query.
     *
     * Wraps the user query as a subquery to enforce the limit reliably,
     * regardless of whether the original query has a LIMIT clause.
     */
    private function applyLimit(string $query, int $limit): string
    {
        return 'SELECT * FROM ('.$query.') AS _blb_limited LIMIT '.$limit;
    }

    private function runValidatedQuery(string $query, int $limit): ToolResult
    {
        $limitedQuery = $this->applyLimit($query, $limit);

        try {
            $results = DB::connection()
                ->getReadPdo()
                ->prepare($limitedQuery);

            $results->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $results->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ToolResult::error('Query error: '.$e->getMessage(), 'query_error');
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        $content = $rows === []
            ? 'Query returned 0 rows.'
            : $this->formatResults($rows, $limit);

        return ToolResult::success($content);
    }

    /**
     * Format query results as a readable text table.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function formatResults(array $rows, int $limit): string
    {
        $rowCount = count($rows);
        $columns = array_keys($rows[0]);

        // Calculate column widths
        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = mb_strlen($col);
        }
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $value = $this->formatValue($row[$col] ?? null);
                $widths[$col] = max($widths[$col], mb_strlen($value));
            }
        }

        // Cap column widths to keep output manageable
        $maxColWidth = 60;
        foreach ($widths as $col => $width) {
            $widths[$col] = min($width, $maxColWidth);
        }

        // Build header
        $header = '| ';
        $separator = '|-';
        foreach ($columns as $col) {
            $header .= mb_str_pad($col, $widths[$col]).' | ';
            $separator .= str_repeat('-', $widths[$col]).'-|-';
        }

        // Build rows
        $lines = [trim($header), trim($separator)];
        foreach ($rows as $row) {
            $line = '| ';
            foreach ($columns as $col) {
                $value = $this->formatValue($row[$col] ?? null);
                if (mb_strlen($value) > $maxColWidth) {
                    $value = BlbStr::preview($value, $maxColWidth - 3, '...');
                }
                $line .= mb_str_pad($value, $widths[$col]).' | ';
            }
            $lines[] = trim($line);
        }

        $result = implode("\n", $lines);

        $rowLabel = $rowCount === 1 ? 'row' : 'rows';
        $suffix = $rowCount >= $limit
            ? "\n\n(".$rowCount.' rows returned — limit reached, there may be more results)'
            : "\n\n(".$rowCount.' '.$rowLabel.' returned)';

        return $result.$suffix;
    }

    /**
     * Format a single cell value for display.
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
