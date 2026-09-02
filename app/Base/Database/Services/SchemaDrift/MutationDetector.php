<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Detects whether a set of AST statements contains schema mutations that
 * cannot be statically replayed. Used by statement-level guards to fail
 * closed on runtime-dependent conditionals and loops.
 */
final class MutationDetector
{
    /** @var list<string> */
    private const SCHEMA_READ_METHODS = [
        'connection', 'disableforeignkeyconstraints', 'enableforeignkeyconstraints',
        'getcolumnlisting', 'getconnection', 'getforeignkeys', 'getindexes',
        'gettablelisting', 'hascolumn', 'hasindex', 'hastable',
    ];

    /** @var list<string> */
    private const OUT_OF_SCOPE_BLUEPRINT_METHODS = [
        'cascadeOnDelete', 'cascadeOnUpdate', 'constrained', 'deferrable', 'dropForeign',
        'foreign', 'initiallyImmediate', 'noActionOnDelete', 'noActionOnUpdate',
        'nullOnDelete', 'references', 'restrictOnDelete', 'restrictOnUpdate', 'on',
    ];

    public function __construct(
        private readonly ParserContext $context,
    ) {}

    /** @param  list<Node\Stmt>  $statements @param  array<string, mixed>  $environment @param  array<string, true>  $activeMethods */
    public function containsSchemaMutation(
        array $statements,
        array $environment = [],
        array $activeMethods = [],
    ): bool {
        return $this->containsStaticCallMutation($statements, $environment, $activeMethods)
            || $this->containsMethodCallMutation($statements, $environment, $activeMethods);
    }

    /** @param  list<Node\Stmt>  $statements @param  array<string, mixed>  $environment @param  array<string, true>  $activeMethods */
    private function containsStaticCallMutation(array $statements, array $environment, array $activeMethods): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if ($call instanceof Node\Expr\StaticCall
                && $this->staticCallMutates($call, $environment, $activeMethods)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $environment @param  array<string, true>  $activeMethods */
    private function staticCallMutates(Node\Expr\StaticCall $call, array $environment, array $activeMethods): bool
    {
        $class = StaticExpressionEvaluator::staticClassName($call);
        $method = StaticExpressionEvaluator::callName($call);

        if ($class === 'schema' && $method !== null && ! in_array($method, self::SCHEMA_READ_METHODS, true)) {
            return $method !== 'table' || $this->schemaTableCallChangesComparedPresence($call);
        }

        if ($class === 'db' && in_array($method, ['statement', 'unprepared'], true)) {
            return $this->rawStatementMutates($call, $environment);
        }

        return $this->containsSelfMethodMutation($call, $class, $method, $environment, $activeMethods);
    }

    /** @param  array<string, mixed>  $environment */
    private function rawStatementMutates(Node\Expr\StaticCall $call, array $environment): bool
    {
        $sql = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;

        if (! is_string($sql)) {
            return true;
        }

        // Classify every statement, not only the first, so a mutation
        // cannot hide behind an exempt opener or a leading comment.
        foreach (SchemaCallProcessor::splitStatements($sql) as $statement) {
            if (preg_match('/^(CREATE|ALTER|DROP)\s/i', $statement) === 1
                && ! SchemaCallProcessor::isRawSchemaOutsideComparison($statement)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Node\Stmt>  $statements @param  array<string, mixed>  $environment @param  array<string, true>  $activeMethods */
    private function containsMethodCallMutation(array $statements, array $environment, array $activeMethods): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if (! $call instanceof Node\Expr\MethodCall) {
                continue;
            }

            $chain = StaticExpressionEvaluator::methodChain($call);
            if ($this->context->evaluator->evaluate($chain['root'], $environment) instanceof BlueprintReference
                && $this->blueprintChainChangesComparedPresence($chain['calls'])) {
                return true;
            }

            $method = StaticExpressionEvaluator::callName($call);
            if ($this->containsThisMethodMutation($call, $method, $environment, $activeMethods)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $environment @param  array<string, true>  $activeMethods */
    private function containsThisMethodMutation(
        Node\Expr\MethodCall $call,
        ?string $method,
        array $environment,
        array $activeMethods,
    ): bool {
        if (! $call->var instanceof Node\Expr\Variable
            || $call->var->name !== 'this'
            || $method === null
            || ! isset($this->context->methods[$method])
            || $this->context->methods[$method]->stmts === null
            || isset($activeMethods[$method])) {
            return false;
        }

        return $this->containsSchemaMutation(
            $this->context->methods[$method]->stmts,
            $this->context->evaluator->methodEnvironment(
                $this->context->methods[$method],
                $this->context->evaluator->resolvedCallArguments($call->args, $environment),
            ),
            [...$activeMethods, $method => true],
        );
    }

    /** @param  array<string, mixed>  $environment @param  array<string, true>  $activeMethods */
    private function containsSelfMethodMutation(
        Node\Expr\StaticCall $call,
        string $class,
        ?string $method,
        array $environment,
        array $activeMethods,
    ): bool {
        if (! in_array($class, ['self', 'static'], true)
            || $method === null
            || ! isset($this->context->methods[$method])
            || $this->context->methods[$method]->stmts === null
            || isset($activeMethods[$method])) {
            return false;
        }

        return $this->containsSchemaMutation(
            $this->context->methods[$method]->stmts,
            $this->context->evaluator->methodEnvironment(
                $this->context->methods[$method],
                $this->context->evaluator->resolvedCallArguments($call->args, $environment),
            ),
            [...$activeMethods, $method => true],
        );
    }

    /**
     * Recognize the one compatibility branch whose fresh-schema state is
     * established by the unconditional create that follows it: rename a legacy
     * table and return. If the old table is source-declared, replay will still
     * fail closed unless a later operation establishes complete table state.
     */
    public function isLegacyRenameGuard(Node\Stmt\If_ $statement): bool
    {
        if ($statement->elseifs !== [] || $statement->else !== null || count($statement->stmts) !== 2) {
            return false;
        }

        [$rename, $return] = $statement->stmts;

        return $rename instanceof Node\Stmt\Expression
            && $rename->expr instanceof Node\Expr\StaticCall
            && StaticExpressionEvaluator::staticClassName($rename->expr) === 'schema'
            && StaticExpressionEvaluator::callName($rename->expr) === 'rename'
            && $return instanceof Node\Stmt\Return_;
    }

    /**
     * Schema presence predicates are deterministic inputs to idempotent repair
     * migrations. Replaying their guarded operation describes the postcondition
     * on both sides of the guard (already satisfied, or repaired).
     */
    public function isSchemaPredicate(Node\Expr $expression): bool
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
            && StaticExpressionEvaluator::staticClassName($expression) === 'schema'
            && ($method = StaticExpressionEvaluator::callName($expression)) !== null
            && in_array($method, self::SCHEMA_READ_METHODS, true);
    }

    public function schemaTableCallChangesComparedPresence(Node\Expr\StaticCall $call): bool
    {
        $closure = $call->args[1]->value ?? null;
        $parameter = ($closure instanceof Node\Expr\Closure && $closure->stmts !== null)
            ? ($closure->params[0]->var ?? null)
            : null;

        if (! $closure instanceof Node\Expr\Closure
            || $closure->stmts === null
            || ! $parameter instanceof Node\Expr\Variable
            || ! is_string($parameter->name)) {
            return true;
        }

        foreach ((new NodeFinder)->findInstanceOf($closure->stmts, Node\Expr\MethodCall::class) as $methodCall) {
            if (! $methodCall instanceof Node\Expr\MethodCall) {
                continue;
            }

            $chain = StaticExpressionEvaluator::methodChain($methodCall);
            if (! $chain['root'] instanceof Node\Expr\Variable || $chain['root']->name !== $parameter->name) {
                continue;
            }

            if ($this->blueprintChainChangesComparedPresence($chain['calls'])) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Node\Expr\MethodCall>  $calls */
    public function blueprintChainChangesComparedPresence(array $calls): bool
    {
        $base = $calls[0] ?? null;
        $method = $base instanceof Node\Expr\MethodCall ? StaticExpressionEvaluator::callName($base) : null;
        if ($method === null || in_array($method, array_map(strtolower(...), self::OUT_OF_SCOPE_BLUEPRINT_METHODS), true)) {
            return false;
        }

        return ! (in_array('change', array_filter(array_map(StaticExpressionEvaluator::callName(...), $calls)), true)
            && $this->indexTypeFromCalls($calls) === null);
    }

    /** @param  list<Node\Expr\MethodCall>  $calls */
    private function indexTypeFromCalls(array $calls): ?DeclaredIndexType
    {
        foreach ($calls as $call) {
            $name = StaticExpressionEvaluator::callName($call);
            if ($name !== null && ($type = $this->indexType($name)) !== null) {
                return $type;
            }
        }

        return null;
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
}
