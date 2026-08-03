<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;

final class MigrationSourceParser
{
    /** @var list<TableOperation> */
    private array $operations = [];

    /** @var list<array{line: int, reason: string}> */
    private array $unreadable = [];

    /** @var array<string, mixed> */
    private array $constants = [];

    /** @var array<string, Node\Stmt\ClassMethod> */
    private array $methods = [];

    /** @var array<string, true> */
    private array $activeMethods = [];

    private string $path = '';

    private readonly Parser $parser;

    /** @var list<string> */
    private const COLUMN_METHODS = [
        'bigInteger',
        'binary',
        'boolean',
        'char',
        'date',
        'dateTime',
        'dateTimeTz',
        'decimal',
        'double',
        'enum',
        'float',
        'foreignId',
        'foreignIdFor',
        'foreignUlid',
        'foreignUuid',
        'geometry',
        'geography',
        'integer',
        'ipAddress',
        'json',
        'jsonb',
        'longText',
        'macAddress',
        'mediumInteger',
        'mediumText',
        'set',
        'smallInteger',
        'string',
        'text',
        'time',
        'timeTz',
        'timestamp',
        'timestampTz',
        'tinyInteger',
        'unsignedBigInteger',
        'unsignedInteger',
        'unsignedMediumInteger',
        'unsignedSmallInteger',
        'unsignedTinyInteger',
        'ulid',
        'uuid',
        'vector',
        'year',
    ];

    /** @var list<string> */
    private const COLUMN_MODIFIERS = [
        'after',
        'algorithm',
        'always',
        'autoIncrement',
        'charset',
        'change',
        'collation',
        'comment',
        'default',
        'deferrable',
        'first',
        'from',
        'generatedAs',
        'invisible',
        'language',
        'nullable',
        'nullsNotDistinct',
        'online',
        'persisted',
        'startingValue',
        'storedAs',
        'unsigned',
        'useCurrent',
        'useCurrentOnUpdate',
        'virtualAs',
    ];

    /** @var list<string> */
    private const OUT_OF_SCOPE_BLUEPRINT_METHODS = [
        'cascadeOnDelete',
        'cascadeOnUpdate',
        'constrained',
        'deferrable',
        'dropForeign',
        'foreign',
        'initiallyImmediate',
        'noActionOnDelete',
        'noActionOnUpdate',
        'nullOnDelete',
        'references',
        'restrictOnDelete',
        'restrictOnUpdate',
        'on',
    ];

    /** @var list<string> */
    private const SCHEMA_READ_METHODS = [
        'connection',
        'disableforeignkeyconstraints',
        'enableforeignkeyconstraints',
        'getcolumnlisting',
        'getconnection',
        'getforeignkeys',
        'getindexes',
        'gettablelisting',
        'hascolumn',
        'hasindex',
        'hastable',
    ];

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
    }

    public function parse(string $path): ParsedMigration
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return new ParsedMigration(
                $this->relativePath($path),
                pathinfo($path, PATHINFO_FILENAME),
                unreadable: [['line' => 1, 'reason' => 'Migration source could not be read.']],
            );
        }

        return $this->parseContents($contents, $this->relativePath($path));
    }

    public function parseContents(string $contents, string $path = 'migration.php'): ParsedMigration
    {
        $this->operations = [];
        $this->unreadable = [];
        $this->constants = [];
        $this->methods = [];
        $this->activeMethods = [];
        $this->path = $path;

        try {
            $nodes = $this->resolvedNodes($contents);
        } catch (Error $e) {
            return new ParsedMigration(
                $path,
                pathinfo($path, PATHINFO_FILENAME),
                unreadable: [[
                    'line' => max(1, $e->getStartLine()),
                    'reason' => 'PHP source could not be parsed: '.$e->getRawMessage(),
                ]],
            );
        }

        $class = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Class_::class);

        if (! $class instanceof Node\Stmt\Class_) {
            return new ParsedMigration(
                $path,
                pathinfo($path, PATHINFO_FILENAME),
                unreadable: [['line' => 1, 'reason' => 'No migration class was found.']],
            );
        }

        $this->loadClassMembers($class);

        $up = $this->methods['up'] ?? null;
        if (! $up instanceof Node\Stmt\ClassMethod || $up->stmts === null) {
            $this->unreadable[] = [
                'line' => $class->getStartLine(),
                'reason' => 'Migration has no source-resolvable up() method.',
            ];
        } else {
            $this->processMethod($up, []);
        }

        return new ParsedMigration(
            $path,
            pathinfo($path, PATHINFO_FILENAME),
            $this->operations,
            $this->unreadable,
        );
    }

    /**
     * @return list<Node\Stmt>
     */
    private function resolvedNodes(string $contents): array
    {
        $nodes = $this->parser->parse($contents) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        return $traverser->traverse($nodes);
    }

    private function loadClassMembers(Node\Stmt\Class_ $class): void
    {
        foreach ($class->getConstants() as $constantStatement) {
            foreach ($constantStatement->consts as $constant) {
                $this->constants[$constant->name->toString()] = $this->evaluate($constant->value, []);
            }
        }

        foreach ($class->getMethods() as $method) {
            $this->methods[strtolower($method->name->toString())] = $method;
        }

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->traits as $traitName) {
                $resolved = $traitName->getAttribute('resolvedName');
                $this->loadTraitMembers($resolved instanceof Node\Name ? $resolved->toString() : $traitName->toString());
            }
        }
    }

    private function loadTraitMembers(string $trait): void
    {
        if (! trait_exists($trait)) {
            $this->unreadable[] = [
                'line' => 1,
                'reason' => sprintf('Migration trait [%s] could not be loaded for static schema inspection.', $trait),
            ];

            return;
        }

        $path = (new ReflectionClass($trait))->getFileName();
        if (! is_string($path) || ($contents = file_get_contents($path)) === false) {
            $this->unreadable[] = [
                'line' => 1,
                'reason' => sprintf('Migration trait [%s] source could not be read.', $trait),
            ];

            return;
        }

        try {
            $nodes = $this->resolvedNodes($contents);
        } catch (Error $e) {
            $this->unreadable[] = [
                'line' => max(1, $e->getStartLine()),
                'reason' => sprintf('Migration trait [%s] could not be parsed: %s', $trait, $e->getRawMessage()),
            ];

            return;
        }

        $traitNode = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Trait_::class);
        if (! $traitNode instanceof Node\Stmt\Trait_) {
            return;
        }

        foreach ($traitNode->getMethods() as $method) {
            $this->methods[strtolower($method->name->toString())] ??= $method;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function processMethod(Node\Stmt\ClassMethod $method, array $arguments): void
    {
        $name = strtolower($method->name->toString());
        if (isset($this->activeMethods[$name]) || $method->stmts === null) {
            return;
        }

        $this->activeMethods[$name] = true;
        $this->processStatements($method->stmts, $this->methodEnvironment($method, $arguments));
        unset($this->activeMethods[$name]);
    }

    /**
     * @param  list<Node\Stmt>  $statements
     * @param  array<string, mixed>  $environment
     */
    private function processStatements(array $statements, array $environment): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Expression) {
                $this->processExpression($statement->expr, $environment);

                if ($statement->expr instanceof Node\Expr\Assign
                    && $statement->expr->var instanceof Node\Expr\Variable
                    && is_string($statement->expr->var->name)) {
                    $environment[$statement->expr->var->name] = $this->evaluate($statement->expr->expr, $environment);
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\If_) {
                $branches = $statement->stmts;
                foreach ($statement->elseifs as $elseif) {
                    array_push($branches, ...$elseif->stmts);
                }
                if ($statement->else !== null) {
                    array_push($branches, ...$statement->else->stmts);
                }

                if ($this->containsSchemaMutation($branches, $environment)
                    && ! ($statement->elseifs === []
                        && $statement->else === null
                        && $this->isSchemaPredicate($statement->cond))
                    && ! $this->isLegacyRenameGuard($statement)) {
                    $this->unreadable($statement, 'Schema mutation inside a runtime-dependent conditional cannot be replayed statically.');
                }

                $this->processStatements($statement->stmts, $environment);
                foreach ($statement->elseifs as $elseif) {
                    $this->processStatements($elseif->stmts, $environment);
                }
                if ($statement->else !== null) {
                    $this->processStatements($statement->else->stmts, $environment);
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\Foreach_) {
                $values = $this->evaluate($statement->expr, $environment);
                if (! is_array($values)) {
                    if ($this->containsSchemaMutation($statement->stmts, $environment)) {
                        $this->unreadable($statement, 'Schema mutation inside a foreach loop has a runtime-dependent iterable.');
                    }

                    continue;
                }

                foreach ($values as $key => $value) {
                    $loopEnvironment = $environment;
                    if ($statement->valueVar instanceof Node\Expr\Variable && is_string($statement->valueVar->name)) {
                        $loopEnvironment[$statement->valueVar->name] = $value;
                    }
                    if ($statement->keyVar instanceof Node\Expr\Variable && is_string($statement->keyVar->name)) {
                        $loopEnvironment[$statement->keyVar->name] = $key;
                    }
                    $this->processStatements($statement->stmts, $loopEnvironment);
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\TryCatch) {
                $this->processStatements($statement->stmts, $environment);
                foreach ($statement->catches as $catch) {
                    if ($this->containsSchemaMutation($catch->stmts, $environment)) {
                        $this->unreadable($catch, 'Schema mutation inside a catch branch cannot be replayed statically.');
                    }
                    $this->processStatements($catch->stmts, $environment);
                }
                if ($statement->finally !== null) {
                    $this->processStatements($statement->finally->stmts, $environment);
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\Switch_) {
                $caseStatements = [];
                foreach ($statement->cases as $case) {
                    array_push($caseStatements, ...$case->stmts);
                    $this->processStatements($case->stmts, $environment);
                }
                if ($this->containsSchemaMutation($caseStatements, $environment)) {
                    $this->unreadable($statement, 'Schema mutation inside a runtime-dependent switch cannot be replayed statically.');
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\For_
                || $statement instanceof Node\Stmt\While_
                || $statement instanceof Node\Stmt\Do_) {
                if ($this->containsSchemaMutation($statement->stmts, $environment)) {
                    $this->unreadable($statement, 'Schema mutation inside a runtime-dependent loop cannot be replayed statically.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function processExpression(Node\Expr $expression, array $environment): void
    {
        if ($expression instanceof Node\Expr\Assign) {
            $this->processExpression($expression->expr, $environment);

            return;
        }

        if ($expression instanceof Node\Expr\StaticCall) {
            $class = $this->staticClassName($expression);
            $method = $this->callName($expression);

            if ($class === 'schema' && $method !== null) {
                $this->processSchemaCall($expression, $method, $environment);

                return;
            }

            if ($class === 'db' && in_array($method, ['statement', 'unprepared'], true)) {
                $this->processRawStatement($expression, $environment);

                return;
            }

            if (in_array($class, ['self', 'static'], true) && $method !== null && isset($this->methods[$method])) {
                $this->processMethodCall($this->methods[$method], $expression->args, $environment);
            }

            return;
        }

        if (! $expression instanceof Node\Expr\MethodCall) {
            return;
        }

        $chain = $this->methodChain($expression);
        $reference = $this->evaluate($chain['root'], $environment);

        if ($reference instanceof BlueprintReference) {
            $this->processBlueprintChain($reference->table, $chain['calls'], $environment);

            return;
        }

        if ($expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this'
            && ($method = $this->callName($expression)) !== null) {
            if (isset($this->methods[$method])) {
                $this->processMethodCall($this->methods[$method], $expression->args, $environment);

                return;
            }

            foreach ($expression->args as $argument) {
                if ($this->evaluate($argument->value, $environment) instanceof BlueprintReference) {
                    $this->unreadable($expression, sprintf('Blueprint helper method [%s] could not be resolved.', $method));

                    return;
                }
            }
        }
    }

    /**
     * @param  list<Node\Arg>  $arguments
     * @param  array<string, mixed>  $environment
     */
    private function processMethodCall(Node\Stmt\ClassMethod $method, array $arguments, array $environment): void
    {
        $this->processMethod($method, $this->resolvedCallArguments($arguments, $environment));
    }

    /**
     * @param  list<Node\Arg>  $arguments
     * @param  array<string, mixed>  $environment
     * @return array<int|string, mixed>
     */
    private function resolvedCallArguments(array $arguments, array $environment): array
    {
        $resolved = [];
        foreach ($arguments as $position => $argument) {
            $key = $argument->name?->toString() ?? $position;
            $resolved[$key] = $this->evaluate($argument->value, $environment);
        }

        return $resolved;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function methodEnvironment(Node\Stmt\ClassMethod $method, array $arguments): array
    {
        $environment = [];

        foreach ($method->params as $position => $parameter) {
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            $environment[$parameter->var->name] = $arguments[$parameter->var->name]
                ?? $arguments[$position]
                ?? ($parameter->default === null ? null : $this->evaluate($parameter->default, []));
        }

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function processSchemaCall(Node\Expr\StaticCall $call, string $method, array $environment): void
    {
        if (in_array($method, self::SCHEMA_READ_METHODS, true)) {
            return;
        }

        $table = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;

        if (in_array($method, ['create', 'table'], true)) {
            if (! is_string($table) || $table === '') {
                $this->unreadable($call, sprintf('Schema::%s table name is runtime-dependent.', $method));

                return;
            }

            if ($method === 'create') {
                $this->operations[] = TableOperation::createTable($table, $call->getStartLine());
            }

            $closure = $call->args[1]->value ?? null;
            if (! $closure instanceof Node\Expr\Closure || $closure->stmts === null) {
                $this->unreadable($call, sprintf('Schema::%s callback is not a source-resolvable closure.', $method));

                return;
            }

            $closureEnvironment = $environment;
            $parameter = $closure->params[0]->var ?? null;
            if ($parameter instanceof Node\Expr\Variable && is_string($parameter->name)) {
                $closureEnvironment[$parameter->name] = new BlueprintReference($table);
            }
            $this->processStatements($closure->stmts, $closureEnvironment);

            return;
        }

        if (in_array($method, ['drop', 'dropifexists'], true)) {
            if (is_string($table) && $table !== '') {
                $this->operations[] = TableOperation::dropTable($table, $call->getStartLine());
            } else {
                $this->unreadable($call, sprintf('Schema::%s table name is runtime-dependent.', $method));
            }

            return;
        }

        if ($method === 'rename') {
            $renameTo = isset($call->args[1]) ? $this->evaluate($call->args[1]->value, $environment) : null;
            if (is_string($table) && $table !== '' && is_string($renameTo) && $renameTo !== '') {
                $this->operations[] = TableOperation::renameTable($table, $renameTo, $call->getStartLine());
            } else {
                $this->unreadable($call, 'Schema::rename table name is runtime-dependent.');
            }

            return;
        }

        $this->unreadable($call, sprintf('Unsupported Schema::%s mutation.', $method));
    }

    /**
     * @param  list<Node\Expr\MethodCall>  $calls
     * @param  array<string, mixed>  $environment
     */
    private function processBlueprintChain(string $table, array $calls, array $environment): void
    {
        if ($calls === []) {
            return;
        }

        $base = array_shift($calls);
        $method = $this->callName($base);
        if ($method === null) {
            $this->unreadable($base, sprintf('Dynamic Blueprint method on table [%s].', $table));

            return;
        }

        if ($this->processDirectIndexMethod($table, $base, $method, $environment)
            || $this->processDropMethod($table, $base, $method, $environment)) {
            return;
        }

        $columns = $this->columnsAddedBy($table, $base, $method, $environment);
        if ($columns === null) {
            if (in_array($method, array_map(strtolower(...), self::OUT_OF_SCOPE_BLUEPRINT_METHODS), true)) {
                return;
            }

            $this->unreadable($base, sprintf('Unsupported Blueprint::%s call on table [%s].', $method, $table));

            return;
        }

        if ($columns === []) {
            return;
        }

        foreach ($columns as $column) {
            $this->operations[] = TableOperation::addColumn($table, $column, $base->getStartLine());
        }

        if (in_array($method, ['id', 'bigincrements', 'increments', 'mediumincrements', 'smallincrements', 'tinyincrements'], true)) {
            $this->operations[] = TableOperation::addIndex(
                $table,
                new DeclaredIndex([$columns[0]], DeclaredIndexType::PRIMARY),
                $base->getStartLine(),
            );
        }

        if (in_array($method, ['morphs', 'nullablemorphs', 'uuidmorphs', 'nullableuuidmorphs', 'ulidmorphs', 'nullableulidmorphs'], true)) {
            $name = isset($base->args[1]) ? $this->evaluate($base->args[1]->value, $environment) : null;
            $this->operations[] = TableOperation::addIndex(
                $table,
                new DeclaredIndex($columns, name: is_string($name) && $name !== '' ? $name : null),
                $base->getStartLine(),
            );
        }

        $indexModifiers = [];

        foreach ($calls as $modifier) {
            $modifierName = $this->callName($modifier);
            if ($modifierName === null) {
                continue;
            }

            $type = $this->indexType($modifierName);
            if ($type !== null) {
                $indexModifiers[$type->value] = $modifier;

                continue;
            }

            if (! in_array($modifierName, array_map(strtolower(...), self::COLUMN_MODIFIERS), true)
                && ! in_array($modifierName, array_map(strtolower(...), self::OUT_OF_SCOPE_BLUEPRINT_METHODS), true)) {
                $this->unreadable($modifier, sprintf('Unsupported Blueprint chain method [%s] on table [%s].', $modifierName, $table));
            }
        }

        // Laravel materializes at most one fluent index command per column and
        // uses this fixed priority, regardless of the chain's call order.
        foreach ([
            DeclaredIndexType::PRIMARY,
            DeclaredIndexType::UNIQUE,
            DeclaredIndexType::INDEX,
        ] as $type) {
            $modifier = $indexModifiers[$type->value] ?? null;
            if (! $modifier instanceof Node\Expr\MethodCall) {
                continue;
            }

            $name = isset($modifier->args[0]) ? $this->evaluate($modifier->args[0]->value, $environment) : null;
            $this->operations[] = TableOperation::addIndex(
                $table,
                new DeclaredIndex($columns, $type, is_string($name) && $name !== '' ? $name : null),
                $modifier->getStartLine(),
            );

            break;
        }
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function processDirectIndexMethod(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): bool {
        $type = $this->indexType($method);
        if ($type === null) {
            return false;
        }

        $columns = $this->stringList($call->args[0]->value ?? null, $environment);
        if ($columns === []) {
            $this->unreadable($call, sprintf('Blueprint::%s columns on table [%s] are runtime-dependent.', $method, $table));

            return true;
        }

        $name = isset($call->args[1]) ? $this->evaluate($call->args[1]->value, $environment) : null;
        $this->operations[] = TableOperation::addIndex(
            $table,
            new DeclaredIndex($columns, $type, is_string($name) && $name !== '' ? $name : null),
            $call->getStartLine(),
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function processDropMethod(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): bool {
        if ($method === 'dropcolumn') {
            if ($call->args === []) {
                $this->unreadable($call, sprintf('Blueprint::dropColumn target on table [%s] is missing.', $table));

                return true;
            }

            foreach ($call->args as $argument) {
                $columns = $this->stringList($argument->value, $environment);
                $value = $this->evaluate($argument->value, $environment);
                if ($columns === [] && $value instanceof FilteredStringCandidates) {
                    $columns = $value->values;
                }

                if ($columns === []) {
                    $this->unreadable($argument, sprintf('Blueprint::dropColumn target on table [%s] is runtime-dependent.', $table));

                    continue;
                }

                foreach ($columns as $column) {
                    $this->operations[] = TableOperation::dropColumn($table, $column, $call->getStartLine());
                }
            }

            return true;
        }

        if ($method === 'dropconstrainedforeignid') {
            $column = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;
            if (is_string($column) && $column !== '') {
                $this->operations[] = TableOperation::dropColumn($table, $column, $call->getStartLine());
            } else {
                $this->unreadable($call, sprintf('Blueprint::dropConstrainedForeignId column on table [%s] is runtime-dependent.', $table));
            }

            return true;
        }

        if ($method === 'renamecolumn') {
            $from = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;
            $to = isset($call->args[1]) ? $this->evaluate($call->args[1]->value, $environment) : null;
            if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
                $this->operations[] = TableOperation::renameColumn($table, $from, $to, $call->getStartLine());
            } else {
                $this->unreadable($call, sprintf('Blueprint::renameColumn names on table [%s] are runtime-dependent.', $table));
            }

            return true;
        }

        if ($method === 'renameindex') {
            $from = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;
            $to = isset($call->args[1]) ? $this->evaluate($call->args[1]->value, $environment) : null;
            if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
                $this->operations[] = TableOperation::renameIndex($table, $from, $to, $call->getStartLine());
            } else {
                $this->unreadable($call, sprintf('Blueprint::renameIndex names on table [%s] are runtime-dependent.', $table));
            }

            return true;
        }

        $dropTypes = [
            'dropindex' => DeclaredIndexType::INDEX,
            'dropunique' => DeclaredIndexType::UNIQUE,
            'dropprimary' => DeclaredIndexType::PRIMARY,
        ];
        if (! isset($dropTypes[$method])) {
            return $this->processShortcutDrop($table, $call, $method, $environment);
        }

        $value = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;
        if (is_string($value) && $value !== '') {
            $this->operations[] = TableOperation::dropIndexNamed($table, $value, $call->getStartLine());
        } elseif (is_array($value)) {
            $columns = array_values(array_filter($value, is_string(...)));
            if ($columns !== []) {
                $this->operations[] = TableOperation::dropIndex(
                    $table,
                    new DeclaredIndex($columns, $dropTypes[$method]),
                    $call->getStartLine(),
                );
            }
        } elseif ($method === 'dropprimary') {
            $this->operations[] = TableOperation::dropIndexNamed($table, strtolower($table.'_primary'), $call->getStartLine());
        } else {
            $this->unreadable($call, sprintf('Blueprint::%s target on table [%s] is runtime-dependent.', $method, $table));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function processShortcutDrop(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): bool {
        $softDeleteColumn = isset($call->args[0])
            ? $this->evaluate($call->args[0]->value, $environment)
            : null;
        $morphName = isset($call->args[0])
            ? $this->evaluate($call->args[0]->value, $environment)
            : null;

        $columns = match ($method) {
            'droptimestamps', 'droptimestampstz' => ['created_at', 'updated_at'],
            'dropsoftdeletes', 'dropsoftdeletestz' => [is_string($softDeleteColumn) && $softDeleteColumn !== '' ? $softDeleteColumn : 'deleted_at'],
            'dropmorphs' => is_string($morphName) && $morphName !== '' ? [$morphName.'_type', $morphName.'_id'] : [],
            default => null,
        };

        if ($columns === null) {
            return false;
        }

        if ($columns === []) {
            $this->unreadable($call, sprintf('Blueprint::%s target on table [%s] is runtime-dependent.', $method, $table));

            return true;
        }

        foreach ($columns as $column) {
            $this->operations[] = TableOperation::dropColumn($table, $column, $call->getStartLine());
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>|null
     */
    private function columnsAddedBy(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): ?array {
        $shortcut = match ($method) {
            'id' => [$this->optionalStringArgument($call, 0, $environment, 'id')],
            'timestamps', 'timestampstz', 'nullabletimestamps' => ['created_at', 'updated_at'],
            'softdeletes', 'softdeletestz' => [$this->optionalStringArgument($call, 0, $environment, 'deleted_at')],
            'remembertoken' => ['remember_token'],
            'morphs', 'nullablemorphs', 'uuidmorphs', 'nullableuuidmorphs', 'ulidmorphs', 'nullableulidmorphs' => ($name = $this->optionalStringArgument($call, 0, $environment)) === null ? [] : [$name.'_type', $name.'_id'],
            default => null,
        };

        if ($shortcut !== null) {
            if ($shortcut === [] || in_array(null, $shortcut, true)) {
                $this->unreadable($call, sprintf('Blueprint::%s column on table [%s] is runtime-dependent.', $method, $table));

                return [];
            }

            return array_values(array_filter($shortcut, is_string(...)));
        }

        if ($method === 'foreignidfor') {
            $column = isset($call->args[1]) ? $this->evaluate($call->args[1]->value, $environment) : null;
            if (! is_string($column) || $column === '') {
                $this->unreadable($call, sprintf('Blueprint::foreignIdFor column on table [%s] requires runtime model metadata.', $table));

                return [];
            }

            return [$column];
        }

        if (in_array($method, ['bigincrements', 'increments', 'mediumincrements', 'smallincrements', 'tinyincrements'], true)
            || in_array($method, array_map(strtolower(...), self::COLUMN_METHODS), true)) {
            $column = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;

            if (! is_string($column) || $column === '') {
                $this->unreadable($call, sprintf('Blueprint::%s column on table [%s] is runtime-dependent.', $method, $table));

                return [];
            }

            return [$column];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function optionalStringArgument(
        Node\Expr\MethodCall $call,
        int $position,
        array $environment,
        ?string $default = null,
    ): ?string {
        if (! isset($call->args[$position])) {
            return $default;
        }

        $value = $this->evaluate($call->args[$position]->value, $environment);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function indexType(string $method): ?DeclaredIndexType
    {
        return match ($method) {
            'index' => DeclaredIndexType::INDEX,
            'unique' => DeclaredIndexType::UNIQUE,
            'primary' => DeclaredIndexType::PRIMARY,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function processRawStatement(Node\Expr\StaticCall $call, array $environment): void
    {
        $sql = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;
        if (! is_string($sql)) {
            $this->unreadable($call, 'DB schema statement is runtime-dependent.');

            return;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX(?:\s+IF\s+NOT\s+EXISTS)?\s+([^\s]+)\s+ON\s+([^\s(]+)\s*\(.+\)(?:\s+WHERE\s+.+)?$/i', $normalized, $matches) === 1) {
            $this->operations[] = TableOperation::addIndex(
                $this->unquoteIdentifier($matches[3]),
                new DeclaredIndex(
                    [],
                    trim($matches[1]) === '' ? DeclaredIndexType::INDEX : DeclaredIndexType::UNIQUE,
                    $this->unquoteIdentifier($matches[2]),
                    compareByName: true,
                ),
                $call->getStartLine(),
            );

            return;
        }

        if (preg_match('/^DROP\s+INDEX(?:\s+IF\s+EXISTS)?\s+([^\s;]+);?$/i', $normalized, $matches) === 1) {
            $this->operations[] = TableOperation::dropIndexNamed(
                null,
                $this->unquoteIdentifier($matches[1]),
                $call->getStartLine(),
            );

            return;
        }

        if (preg_match('/^(CREATE|ALTER|DROP)\s/i', $normalized) === 1) {
            $this->unreadable($call, 'Raw schema statement is outside the supported CREATE/DROP INDEX forms.');
        }
    }

    private function unquoteIdentifier(string $identifier): string
    {
        return trim($identifier, '"`[]');
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>
     */
    private function stringList(?Node\Expr $expression, array $environment): array
    {
        if ($expression === null) {
            return [];
        }

        $value = $this->evaluate($expression, $environment);
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function evaluate(Node\Expr $expression, array $environment): mixed
    {
        if ($expression instanceof Node\Scalar\String_
            || $expression instanceof Node\Scalar\LNumber
            || $expression instanceof Node\Scalar\DNumber) {
            return $expression->value;
        }

        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => null,
            };
        }

        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return $environment[$expression->name] ?? null;
        }

        if ($expression instanceof Node\Expr\ClassConstFetch && $expression->name instanceof Node\Identifier) {
            $class = $expression->class instanceof Node\Name ? strtolower($expression->class->toString()) : '';
            if (in_array($class, ['self', 'static'], true)) {
                return $this->constants[$expression->name->toString()] ?? null;
            }
        }

        if ($expression instanceof Node\Expr\Array_) {
            $values = [];
            foreach ($expression->items as $position => $item) {
                if ($item === null) {
                    continue;
                }
                $value = $this->evaluate($item->value, $environment);
                $key = $item->key === null ? $position : $this->evaluate($item->key, $environment);
                $values[is_int($key) || is_string($key) ? $key : $position] = $value;
            }

            return $values;
        }

        if ($expression instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->evaluate($expression->left, $environment);
            $right = $this->evaluate($expression->right, $environment);

            return (is_string($left) || is_numeric($left)) && (is_string($right) || is_numeric($right))
                ? (string) $left.(string) $right
                : null;
        }

        if ($expression instanceof Node\Expr\BinaryOp\Coalesce) {
            return $this->evaluate($expression->left, $environment) ?? $this->evaluate($expression->right, $environment);
        }

        if ($expression instanceof Node\Expr\Ternary) {
            $if = $expression->if === null ? null : $this->evaluate($expression->if, $environment);
            $else = $this->evaluate($expression->else, $environment);

            return $if === $else ? $if : null;
        }

        if ($expression instanceof Node\Expr\Cast\String_) {
            $value = $this->evaluate($expression->expr, $environment);

            return is_scalar($value) ? (string) $value : null;
        }

        if ($expression instanceof Node\Expr\MethodCall) {
            $chain = $this->methodChain($expression);
            $root = $chain['root'];
            if (! $root instanceof Node\Expr\FuncCall
                || ! $root->name instanceof Node\Name
                || strtolower($root->name->toString()) !== 'collect'
                || ! isset($root->args[0])) {
                return null;
            }

            $values = $this->evaluate($root->args[0]->value, $environment);
            if (! is_array($values)) {
                return null;
            }

            $methods = array_map($this->callName(...), $chain['calls']);
            if (in_array(null, $methods, true) || array_diff($methods, ['filter', 'values', 'all']) !== []) {
                return null;
            }

            $strings = array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== ''));

            return in_array('filter', $methods, true)
                ? new FilteredStringCandidates($strings)
                : $strings;
        }

        return null;
    }

    /**
     * @return array{root: Node\Expr, calls: list<Node\Expr\MethodCall>}
     */
    private function methodChain(Node\Expr\MethodCall $method): array
    {
        $calls = [];
        $root = $method;

        while ($root instanceof Node\Expr\MethodCall) {
            array_unshift($calls, $root);
            $root = $root->var;
        }

        return ['root' => $root, 'calls' => $calls];
    }

    private function staticClassName(Node\Expr\StaticCall $call): string
    {
        if (! $call->class instanceof Node\Name) {
            return '';
        }

        $resolved = $call->class->getAttribute('resolvedName');
        $name = $resolved instanceof Node\Name ? $resolved->toString() : $call->class->toString();
        $segments = explode('\\', $name);

        return strtolower((string) end($segments));
    }

    private function callName(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null;
    }

    /**
     * @param  list<Node\Stmt>  $statements
     * @param  array<string, mixed>  $environment
     * @param  array<string, true>  $activeMethods
     */
    private function containsSchemaMutation(
        array $statements,
        array $environment = [],
        array $activeMethods = [],
    ): bool {
        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if (! $call instanceof Node\Expr\StaticCall) {
                continue;
            }

            $class = $this->staticClassName($call);
            $method = $this->callName($call);

            if ($class === 'schema' && $method !== null && ! in_array($method, self::SCHEMA_READ_METHODS, true)) {
                if ($method !== 'table' || $this->schemaTableCallChangesComparedPresence($call)) {
                    return true;
                }

                continue;
            }

            if ($class !== 'db' || ! in_array($method, ['statement', 'unprepared'], true)) {
                if (in_array($class, ['self', 'static'], true)
                    && $method !== null
                    && isset($this->methods[$method])
                    && $this->methods[$method]->stmts !== null
                    && ! isset($activeMethods[$method])
                    && $this->containsSchemaMutation(
                        $this->methods[$method]->stmts,
                        $this->methodEnvironment(
                            $this->methods[$method],
                            $this->resolvedCallArguments($call->args, $environment),
                        ),
                        [...$activeMethods, $method => true],
                    )) {
                    return true;
                }

                continue;
            }

            $sql = isset($call->args[0]) ? $this->evaluate($call->args[0]->value, $environment) : null;
            if (! is_string($sql) || preg_match('/^\s*(CREATE|ALTER|DROP)\s/i', $sql) === 1) {
                return true;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if (! $call instanceof Node\Expr\MethodCall) {
                continue;
            }

            $chain = $this->methodChain($call);
            if ($this->evaluate($chain['root'], $environment) instanceof BlueprintReference
                && $this->blueprintChainChangesComparedPresence($chain['calls'])) {
                return true;
            }

            if (! $call->var instanceof Node\Expr\Variable
                || $call->var->name !== 'this'
                || ($method = $this->callName($call)) === null
                || ! isset($this->methods[$method])
                || $this->methods[$method]->stmts === null
                || isset($activeMethods[$method])) {
                continue;
            }

            if ($this->containsSchemaMutation(
                $this->methods[$method]->stmts,
                $this->methodEnvironment(
                    $this->methods[$method],
                    $this->resolvedCallArguments($call->args, $environment),
                ),
                [...$activeMethods, $method => true],
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recognize the one compatibility branch whose fresh-schema state is
     * established by the unconditional create that follows it: rename a legacy
     * table and return. If the old table is source-declared, replay will still
     * fail closed unless a later operation establishes complete table state.
     */
    private function isLegacyRenameGuard(Node\Stmt\If_ $statement): bool
    {
        if ($statement->elseifs !== [] || $statement->else !== null || count($statement->stmts) !== 2) {
            return false;
        }

        [$rename, $return] = $statement->stmts;

        return $rename instanceof Node\Stmt\Expression
            && $rename->expr instanceof Node\Expr\StaticCall
            && $this->staticClassName($rename->expr) === 'schema'
            && $this->callName($rename->expr) === 'rename'
            && $return instanceof Node\Stmt\Return_;
    }

    /**
     * Schema presence predicates are deterministic inputs to idempotent repair
     * migrations. Replaying their guarded operation describes the postcondition
     * on both sides of the guard (already satisfied, or repaired).
     */
    private function isSchemaPredicate(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\BooleanNot) {
            return $this->isSchemaPredicate($expression->expr);
        }

        if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd
            || $expression instanceof Node\Expr\BinaryOp\LogicalAnd
            || $expression instanceof Node\Expr\BinaryOp\BooleanOr
            || $expression instanceof Node\Expr\BinaryOp\LogicalOr) {
            return $this->isSchemaPredicate($expression->left)
                && $this->isSchemaPredicate($expression->right);
        }

        return $expression instanceof Node\Expr\StaticCall
            && $this->staticClassName($expression) === 'schema'
            && ($method = $this->callName($expression)) !== null
            && in_array($method, self::SCHEMA_READ_METHODS, true);
    }

    private function schemaTableCallChangesComparedPresence(Node\Expr\StaticCall $call): bool
    {
        $closure = $call->args[1]->value ?? null;
        if (! $closure instanceof Node\Expr\Closure || $closure->params === []) {
            return true;
        }

        $parameter = $closure->params[0]->var;
        if (! $parameter instanceof Node\Expr\Variable || ! is_string($parameter->name)) {
            return true;
        }

        foreach ((new NodeFinder)->findInstanceOf($closure->stmts ?? [], Node\Expr\MethodCall::class) as $methodCall) {
            if (! $methodCall instanceof Node\Expr\MethodCall) {
                continue;
            }

            $chain = $this->methodChain($methodCall);
            if (! $chain['root'] instanceof Node\Expr\Variable || $chain['root']->name !== $parameter->name) {
                continue;
            }

            if ($this->blueprintChainChangesComparedPresence($chain['calls'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Node\Expr\MethodCall>  $calls
     */
    private function blueprintChainChangesComparedPresence(array $calls): bool
    {
        $base = $calls[0] ?? null;
        $method = $base instanceof Node\Expr\MethodCall ? $this->callName($base) : null;
        if ($method === null || in_array($method, array_map(strtolower(...), self::OUT_OF_SCOPE_BLUEPRINT_METHODS), true)) {
            return false;
        }

        return ! (in_array('change', array_filter(array_map($this->callName(...), $calls)), true)
            && $this->indexTypeFromCalls($calls) === null);
    }

    /**
     * @param  list<Node\Expr\MethodCall>  $calls
     */
    private function indexTypeFromCalls(array $calls): ?DeclaredIndexType
    {
        foreach ($calls as $call) {
            if (($type = $this->indexType((string) $this->callName($call))) !== null) {
                return $type;
            }
        }

        return null;
    }

    private function unreadable(Node $node, string $reason): void
    {
        $this->unreadable[] = [
            'line' => max(1, $node->getStartLine()),
            'reason' => $reason,
        ];
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $normalizedBase)
            ? substr($normalized, strlen($normalizedBase))
            : $normalized;
    }
}

final readonly class BlueprintReference
{
    public function __construct(public string $table) {}
}

/**
 * A finite source list filtered by live-schema presence. It is safe to replay
 * all candidates only for a drop: present candidates are dropped and absent
 * candidates already satisfy the same postcondition.
 */
final readonly class FilteredStringCandidates
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(public array $values) {}
}
