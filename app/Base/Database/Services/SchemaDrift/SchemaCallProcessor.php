<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Node;

/**
 * Processes Schema facade and DB facade static calls from migration
 * source into TableOperation records.
 */
final class SchemaCallProcessor
{
    /** @var list<string> */
    private const SCHEMA_READ_METHODS = [
        'connection', 'disableforeignkeyconstraints', 'enableforeignkeyconstraints',
        'getcolumnlisting', 'getconnection', 'getforeignkeys', 'getindexes',
        'gettablelisting', 'hascolumn', 'hasindex', 'hastable',
    ];

    public function __construct(
        private readonly ParserContext $context,
    ) {}

    /** @param  array<string, mixed>  $environment */
    public function processStaticCallExpression(Node\Expr\StaticCall $expression, array $environment): void
    {
        $class = StaticExpressionEvaluator::staticClassName($expression);
        $method = StaticExpressionEvaluator::callName($expression);

        if ($class === 'schema' && $method !== null) {
            $this->processSchemaCall($expression, $method, $environment);

            return;
        }

        if ($class === 'db' && in_array($method, ['statement', 'unprepared'], true)) {
            $this->processRawStatement($expression, $environment);

            return;
        }

        if (in_array($class, ['self', 'static'], true) && $method !== null && isset($this->context->methods[$method])) {
            ($this->context->processMethod)(
                $this->context->methods[$method],
                $this->context->evaluator->resolvedCallArguments($expression->args, $environment),
            );
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processSchemaCall(Node\Expr\StaticCall $call, string $method, array $environment): void
    {
        if (in_array($method, self::SCHEMA_READ_METHODS, true)) {
            return;
        }

        $table = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;

        match (true) {
            in_array($method, ['create', 'table'], true) => $this->processCreateOrTable($call, $method, $table, $environment),
            in_array($method, ['drop', 'dropifexists'], true) => $this->processDrop($call, $method, $table),
            $method === 'rename' => $this->processRename($call, $table, $environment),
            default => $this->context->reportUnreadable($call, sprintf('Unsupported Schema::%s mutation.', $method)),
        };
    }

    /** @param  array<string, mixed>  $environment */
    private function processCreateOrTable(Node\Expr\StaticCall $call, string $method, mixed $table, array $environment): void
    {
        if (! is_string($table) || $table === '') {
            $this->context->reportUnreadable($call, sprintf('Schema::%s table name is runtime-dependent.', $method));

            return;
        }

        if ($method === 'create') {
            $this->context->addOperation(TableOperation::createTable($table, $call->getStartLine()));
        }

        $closure = $call->args[1]->value ?? null;
        if (! $closure instanceof Node\Expr\Closure || $closure->stmts === null) {
            $this->context->reportUnreadable($call, sprintf('Schema::%s callback is not a source-resolvable closure.', $method));

            return;
        }

        $closureEnvironment = $environment;
        $parameter = $closure->params[0]->var ?? null;
        if ($parameter instanceof Node\Expr\Variable && is_string($parameter->name)) {
            $closureEnvironment[$parameter->name] = new BlueprintReference($table);
        }
        ($this->context->processStatements)($closure->stmts, $closureEnvironment);
    }

    private function processDrop(Node\Expr\StaticCall $call, string $method, mixed $table): void
    {
        if (is_string($table) && $table !== '') {
            $this->context->addOperation(TableOperation::dropTable($table, $call->getStartLine()));
        } else {
            $this->context->reportUnreadable($call, sprintf('Schema::%s table name is runtime-dependent.', $method));
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processRename(Node\Expr\StaticCall $call, mixed $table, array $environment): void
    {
        $renameTo = isset($call->args[1]) ? $this->context->evaluator->evaluate($call->args[1]->value, $environment) : null;
        if (is_string($table) && $table !== '' && is_string($renameTo) && $renameTo !== '') {
            $this->context->addOperation(TableOperation::renameTable($table, $renameTo, $call->getStartLine()));
        } else {
            $this->context->reportUnreadable($call, 'Schema::rename table name is runtime-dependent.');
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processRawStatement(Node\Expr\StaticCall $call, array $environment): void
    {
        $sql = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        if (! is_string($sql)) {
            $this->context->reportUnreadable($call, 'DB schema statement is runtime-dependent.');

            return;
        }

        // A single DB::statement()/DB::unprepared() can carry several
        // statements. Classify each one, not only the first, so a non-exempt
        // statement cannot hide behind an exempt opener or a leading comment.
        foreach (self::splitStatements($sql) as $statement) {
            $this->processNormalizedStatement($call, $statement);
        }
    }

    private function processNormalizedStatement(Node\Expr\StaticCall $call, string $normalized): void
    {
        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX/i', $normalized, $prefix) === 1) {
            $this->processCreateIndexStatement($call, $normalized, $prefix);

            return;
        }

        if (preg_match('/^DROP\s+INDEX(?:\s+IF\s+EXISTS)?\s+(\S+);?$/i', $normalized, $matches) === 1) {
            $this->context->addOperation(TableOperation::dropIndexNamed(
                null,
                $this->unquoteIdentifier($matches[1]),
                $call->getStartLine(),
            ));

            return;
        }

        if (self::isRawSchemaOutsideComparison($normalized)) {
            return;
        }

        if (preg_match('/^(CREATE|ALTER|DROP)\s/i', $normalized) === 1) {
            $this->context->reportUnreadable($call, 'Raw schema statement is outside the supported CREATE/DROP INDEX forms.');
        }
    }

    public static function isRawSchemaOutsideComparison(string $sql): bool
    {
        return preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+ADD\s+CONSTRAINT\s+\S+\s+CHECK\b/i', $sql) === 1
            // Triggers and the functions they call are outside the compared
            // schema in exactly the same way: the comparator knows tables,
            // columns and indexes, and neither of these is one of them.
            //
            // Create and drop are covered together deliberately. Exempting
            // CREATE TRIGGER alone meant portable guard code was readable on
            // SQLite and unreadable on PostgreSQL, which is where the trigger
            // does the work. Leaving the DROP forms out would have reproduced
            // that asymmetry one revision later: replacing a trigger is
            // ordinarily written DROP TRIGGER then CREATE TRIGGER, and only
            // the first statement of a string is inspected, so statement order
            // would have decided whether migrate came back clean.
            //
            // TRIGGER and FUNCTION share one alternation rather than sitting
            // on separate lines so that each flag is pinned by a test on every
            // form it governs. Split across two arms, a test could pin /i on
            // one and \b on the other and leave the remaining pair free.
            || preg_match('/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:TRIGGER|FUNCTION)\b/i', $sql) === 1
            || preg_match('/^\s*DROP\s+(?:TRIGGER|FUNCTION)\b/i', $sql) === 1;
    }

    /**
     * Split a raw SQL string into its significant statements.
     *
     * Statements are separated by semicolons that sit outside a single-quoted
     * literal, a double-quoted identifier, a dollar-quoted body, or a comment.
     * Leading whitespace and comments are stripped from each statement and the
     * remaining whitespace is collapsed so the classification regexes see a
     * single, head-anchored statement rather than the whole string. Empty and
     * comment-only statements are dropped.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                $i++;

                continue;
            }

            if ($char === '-' && $next === '-') {
                // Line comment: a semicolon on this line is comment text, not a
                // separator. Consume through the comment; the newline is then
                // handled by the ordinary loop.
                $current .= '--';
                $i += 2;
                while ($i < $length && $sql[$i] !== "\n") {
                    $current .= $sql[$i];
                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '*') {
                // Block comment: a semicolon inside it is not a separator.
                $current .= '/*';
                $i += 2;
                while ($i < $length) {
                    $current .= $sql[$i];
                    if ($i + 1 < $length && $sql[$i] === '*' && $sql[$i + 1] === '/') {
                        $current .= '/';
                        $i += 2;
                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                // Quoted literal or identifier: semicolons inside it are data.
                $quote = $char;
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $current .= $sql[$i];
                    if ($sql[$i] === $quote) {
                        // A doubled quote is an escaped quote, not the terminator.
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $current .= $quote;
                            $i += 2;

                            continue;
                        }
                        $i++;

                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($char === '$') {
                // Dollar-quoted body: $$ ... $$ or $tag$ ... $tag$. The body
                // frequently contains semicolons (a plpgsql function), which
                // must not split the statement.
                $tagEnd = $i + 1;
                while ($tagEnd < $length && self::isDollarTagByte($sql[$tagEnd])) {
                    $tagEnd++;
                }
                if ($tagEnd < $length && $sql[$tagEnd] === '$') {
                    $tag = substr($sql, $i, $tagEnd - $i + 1);
                    $current .= $tag;
                    $i = $tagEnd + 1;
                    while ($i < $length) {
                        if (substr($sql, $i, strlen($tag)) === $tag) {
                            $current .= $tag;
                            $i += strlen($tag);

                            break;
                        }
                        $current .= $sql[$i];
                        $i++;
                    }

                    continue;
                }

                // Not a dollar quote: '$' is literal (e.g. a positional
                // parameter like $1).
                $current .= $char;
                $i++;

                continue;
            }

            $current .= $char;
            $i++;
        }

        $statements[] = $current;

        $significant = [];
        foreach ($statements as $statement) {
            $statement = self::stripLeadingTrivia($statement);
            if ($statement === '') {
                continue;
            }
            $significant[] = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);
        }

        return $significant;
    }

    private static function isDollarTagByte(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= '0' && $char <= '9')
            || $char === '_';
    }

    /**
     * Strip leading whitespace and comments so a statement that opens with a
     * comment still classifies by the keyword that follows it.
     */
    private static function stripLeadingTrivia(string $statement): string
    {
        $statement = ltrim($statement);
        while ($statement !== '') {
            if (str_starts_with($statement, '--')) {
                $newline = strpos($statement, "\n");
                $statement = $newline === false ? '' : ltrim(substr($statement, $newline + 1));
            } elseif (str_starts_with($statement, '/*')) {
                $end = strpos($statement, '*/');
                $statement = $end === false ? '' : ltrim(substr($statement, $end + 2));
            } else {
                break;
            }
        }

        return $statement;
    }

    /** @param  list<string>  $prefix */
    private function processCreateIndexStatement(Node\Expr\StaticCall $call, string $normalized, array $prefix): void
    {
        $isUnique = trim($prefix[1] ?? '') !== '';
        $afterIndex = trim(substr($normalized, strlen($prefix[0])));

        // Strip optional IF NOT EXISTS
        if (preg_match('/^IF\s+NOT\s+EXISTS\s+/i', $afterIndex, $ifNotExists) === 1) {
            $afterIndex = substr($afterIndex, strlen($ifNotExists[0]));
        }

        if (preg_match('/^(\S+)\s+ON\s+(\S+?)\s*\((.+)\)/i', $afterIndex, $matches) !== 1) {
            $this->context->reportUnreadable($call, 'Raw schema statement is outside the supported CREATE/DROP INDEX forms.');

            return;
        }

        $this->context->addOperation(TableOperation::addIndex(
            $this->unquoteIdentifier($matches[2]),
            new DeclaredIndex(
                [],
                $isUnique ? DeclaredIndexType::UNIQUE : DeclaredIndexType::INDEX,
                $this->unquoteIdentifier($matches[1]),
                compareByName: true,
            ),
            $call->getStartLine(),
        ));
    }

    private function unquoteIdentifier(string $identifier): string
    {
        return trim($identifier, '"`[]');
    }
}
