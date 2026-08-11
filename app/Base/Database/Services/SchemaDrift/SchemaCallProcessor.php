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

        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        $this->processNormalizedStatement($call, $normalized);
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
            || preg_match('/^\s*CREATE\s+TRIGGER\b/i', $sql) === 1;
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
