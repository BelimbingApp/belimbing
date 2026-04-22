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
use Illuminate\Support\Facades\DB;

/**
 * Write-capable database tool for Agents.
 *
 * Allows an agent to execute INSERT, UPDATE, and DELETE statements against the
 * application database. DDL and administrative statements (DROP, ALTER,
 * TRUNCATE, CREATE, GRANT, REVOKE, etc.) are rejected at the SQL parsing level.
 *
 * The tool description instructs the agent to exercise judgement: warn before
 * risky operations, refuse clearly destructive ones, and always explain what
 * a statement will do before executing it.
 *
 * Gated by `ai.tool_edit_data.execute` authz capability.
 */
class EditDataTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const MAX_AFFECTED_ROWS = 1000;

    /**
     * DML keywords the tool accepts.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'INSERT',
        'UPDATE',
        'DELETE',
    ];

    /**
     * SQL keywords that indicate a DDL or administrative operation.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYWORDS = [
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

    /**
     * Tables that must never be modified through this tool.
     *
     * @var list<string>
     */
    private const PROTECTED_TABLES = [
        'migrations',
        'base_database_tables',
        'base_database_seeders',
        'authz_roles',
        'authz_capabilities',
        'authz_role_capabilities',
        'authz_principal_capabilities',
        'authz_principal_roles',
    ];

    public function name(): string
    {
        return 'edit_data';
    }

    public function description(): string
    {
        return 'Execute a write SQL statement (INSERT, UPDATE, DELETE) against the Belimbing database. '
            .'Use this when the user asks you to modify data — add records, update values, or remove rows. '
            .'DDL statements (DROP, ALTER, TRUNCATE, CREATE) are forbidden. '
            ."\n\n"
            .'IMPORTANT — You are a guardrail. Exercise your judgement before every call: '
            .'1) ALWAYS explain what the statement will do and how many rows it will affect BEFORE executing. '
            .'2) WARN the user if the operation is risky — e.g., DELETE or UPDATE without a narrow WHERE clause, '
            .'modifying columns that look like foreign keys or primary keys, or touching tables with many rows. '
            .'3) REFUSE operations you believe are clearly destructive or nonsensical — e.g., "delete all users", '
            .'"truncate the orders table", mass updates that would corrupt referential integrity. '
            .'Politely explain why and suggest a safer alternative. '
            .'4) If the user insists after your warning, you may proceed — but log your concern in the response. '
            .'You are trusted to protect the data. Act accordingly.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'statement',
                'The SQL write statement to execute. '
                    .'Only INSERT, UPDATE, and DELETE statements are allowed — no DDL (DROP, ALTER, CREATE, TRUNCATE). '
                    .'Use standard SQL compatible with PostgreSQL. '
                    .'Examples: "UPDATE employees SET status = \'inactive\' WHERE id = 42", '
                    .'"DELETE FROM failed_jobs WHERE failed_at < \'2025-01-01\'".'
            )->required();
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DATA;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::HIGH_IMPACT;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_edit_data.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Edit Data',
            'summary' => 'Modify data in Belimbing using safe, guarded SQL writes.',
            'explanation' => 'Executes INSERT, UPDATE, and DELETE statements against the application database. '
                .'DDL and administrative statements (DROP, ALTER, TRUNCATE, CREATE, GRANT, REVOKE) are rejected '
                .'at the SQL parsing level. The agent is instructed to warn before risky operations and refuse '
                .'clearly destructive ones. Protected system tables (migrations, authz, database registry) '
                .'cannot be modified through this tool.',
            'setup_requirements' => [
                'No external API key required',
                'Database connection must be available',
            ],
            'test_examples' => [
                [
                    'label' => 'Update a single row',
                    'input' => ['statement' => "UPDATE employees SET status = 'inactive' WHERE id = 42"],
                    'runnable' => false,
                ],
                [
                    'label' => 'Delete old failed jobs',
                    'input' => ['statement' => "DELETE FROM failed_jobs WHERE failed_at < '2025-01-01'"],
                    'runnable' => false,
                ],
            ],
            'health_checks' => [
                'Database reachable',
                'Write SQL validator active',
            ],
            'limits' => [
                'Maximum '.self::MAX_AFFECTED_ROWS.' affected rows per statement',
                'No DDL (DROP, ALTER, TRUNCATE, CREATE)',
                'Protected system tables cannot be modified',
                'Agent will warn or refuse dangerous operations',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $statement = $this->requireString($arguments, 'statement');

        // Remove trailing semicolons (prevents multi-statement injection)
        $statement = rtrim($statement, ';');

        $this->validateStatement($statement);

        return $this->runValidatedStatement($statement);
    }

    /**
     * Validate that the statement is a safe DML operation.
     *
     * Checks are ordered to produce the most specific error message:
     * multi-statement first, then forbidden keywords, then allowed prefixes,
     * then protected tables.
     *
     * @throws ToolArgumentException If the statement is not a safe DML operation
     */
    private function validateStatement(string $statement): void
    {
        if (str_contains($statement, ';')) {
            throw new ToolArgumentException('Multiple statements are not allowed.');
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.$keyword.'\b/i', $statement)) {
                throw new ToolArgumentException(
                    $keyword.' operations are not allowed. Only INSERT, UPDATE, and DELETE are permitted.'
                );
            }
        }

        $normalised = strtoupper(ltrim($statement));
        $isAllowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalised, $prefix)) {
                $isAllowed = true;

                break;
            }
        }

        if (! $isAllowed) {
            throw new ToolArgumentException(
                'Only INSERT, UPDATE, and DELETE statements are allowed. '
                .'For read queries, use the query_data tool instead.'
            );
        }

        foreach (self::PROTECTED_TABLES as $table) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $statement)) {
                throw new ToolArgumentException(
                    "The table '{$table}' is protected and cannot be modified through this tool."
                );
            }
        }
    }

    private function runValidatedStatement(string $statement): ToolResult
    {
        try {
            $affectedRows = DB::affectingStatement($statement);
        } catch (\Throwable $e) {
            return ToolResult::error('Statement error: '.$e->getMessage(), 'statement_error');
        }

        if ($affectedRows > self::MAX_AFFECTED_ROWS) {
            return ToolResult::success(
                "Statement executed. {$affectedRows} rows affected. "
                .'WARNING: This exceeded the expected safety threshold of '.self::MAX_AFFECTED_ROWS.' rows. '
                .'Verify the result carefully.'
            );
        }

        $rowLabel = $affectedRows === 1 ? 'row' : 'rows';

        return ToolResult::success("Statement executed successfully. {$affectedRows} {$rowLabel} affected.");
    }
}
