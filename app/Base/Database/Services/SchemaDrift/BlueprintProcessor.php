<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Node;

/**
 * Processes Blueprint method chains from migration closures into
 * TableOperation records, preserving Laravel's fluent index priority
 * and shortcut column semantics.
 */
final class BlueprintProcessor
{
    /** @var list<string> */
    private const COLUMN_MODIFIERS = [
        'after', 'algorithm', 'always', 'autoIncrement', 'charset', 'change', 'collation',
        'comment', 'default', 'deferrable', 'first', 'from', 'generatedAs', 'invisible',
        'language', 'nullable', 'nullsNotDistinct', 'online', 'persisted', 'startingValue',
        'storedAs', 'unsigned', 'useCurrent', 'useCurrentOnUpdate', 'virtualAs',
    ];

    /** @var list<string> */
    private const OUT_OF_SCOPE_BLUEPRINT_METHODS = [
        'cascadeOnDelete', 'cascadeOnUpdate', 'constrained', 'deferrable', 'dropForeign',
        'foreign', 'initiallyImmediate', 'noActionOnDelete', 'noActionOnUpdate',
        'nullOnDelete', 'references', 'restrictOnDelete', 'restrictOnUpdate', 'on',
    ];

    private readonly ColumnResolver $columnResolver;

    public function __construct(
        private readonly ParserContext $context,
    ) {
        $this->columnResolver = new ColumnResolver($context);
    }

    /**
     * Dispatch a method call expression that may reference a Blueprint or
     * a $this->method() helper.
     */
    public function processMethodCallExpression(Node\Expr\MethodCall $expression, array $environment): void
    {
        $chain = StaticExpressionEvaluator::methodChain($expression);
        $reference = $this->context->evaluator->evaluate($chain['root'], $environment);

        if ($reference instanceof BlueprintReference) {
            $this->processChain($reference->table, $chain['calls'], $environment);

            return;
        }

        $this->processThisMethodCall($expression, $environment);
    }

    /** @param  array<string, mixed>  $environment */
    private function processThisMethodCall(Node\Expr\MethodCall $expression, array $environment): void
    {
        if (! $expression->var instanceof Node\Expr\Variable
            || $expression->var->name !== 'this'
            || ($method = StaticExpressionEvaluator::callName($expression)) === null) {
            return;
        }

        if (isset($this->context->methods[$method])) {
            ($this->context->processMethod)(
                $this->context->methods[$method],
                $this->context->evaluator->resolvedCallArguments($expression->args, $environment),
            );

            return;
        }

        foreach ($expression->args as $argument) {
            if ($this->context->evaluator->evaluate($argument->value, $environment) instanceof BlueprintReference) {
                $this->context->reportUnreadable(
                    $expression,
                    sprintf('Blueprint helper method [%s] could not be resolved.', $method),
                );

                return;
            }
        }
    }

    /**
     * Process a fluent Blueprint method chain rooted at a Schema::create/table
     * closure parameter.
     *
     * @param  list<Node\Expr\MethodCall>  $calls
     * @param  array<string, mixed>  $environment
     */
    public function processChain(string $table, array $calls, array $environment): void
    {
        if ($calls === []) {
            return;
        }

        $this->processNonEmptyChain($table, $calls, $environment);
    }

    /** @param  list<Node\Expr\MethodCall>  $calls @param  array<string, mixed>  $environment */
    private function processNonEmptyChain(string $table, array $calls, array $environment): void
    {
        $base = array_shift($calls);
        $method = StaticExpressionEvaluator::callName($base);
        if ($method === null) {
            $this->context->reportUnreadable($base, sprintf('Dynamic Blueprint method on table [%s].', $table));

            return;
        }

        if ($this->processDirectIndex($table, $base, $method, $environment)
            || $this->processDrop($table, $base, $method, $environment)) {
            return;
        }

        $columns = $this->columnResolver->columnsAddedBy($table, $base, $method, $environment);
        if ($columns === null || $columns === []) {
            if ($columns === null && ! in_array($method, array_map(strtolower(...), self::OUT_OF_SCOPE_BLUEPRINT_METHODS), true)) {
                $this->context->reportUnreadable(
                    $base,
                    sprintf('Unsupported Blueprint::%s call on table [%s].', $method, $table),
                );
            }

            return;
        }

        foreach ($columns as $column) {
            $this->context->addOperation(TableOperation::addColumn($table, $column, $base->getStartLine()));
        }

        $this->applyAutoIndex($table, $base, $method, $columns, $environment);
        $this->processColumnModifiers($table, $calls, $columns, $environment);
    }

    /** @param  list<string>  $columns @param  array<string, mixed>  $environment */
    private function applyAutoIndex(
        string $table,
        Node\Expr\MethodCall $base,
        string $method,
        array $columns,
        array $environment,
    ): void {
        if (in_array($method, ['id', 'bigincrements', 'increments', 'mediumincrements', 'smallincrements', 'tinyincrements'], true)) {
            $this->context->addOperation(TableOperation::addIndex(
                $table,
                new DeclaredIndex([$columns[0]], DeclaredIndexType::PRIMARY),
                $base->getStartLine(),
            ));
        }

        if (in_array($method, ['morphs', 'nullablemorphs', 'uuidmorphs', 'nullableuuidmorphs', 'ulidmorphs', 'nullableulidmorphs'], true)) {
            $name = isset($base->args[1]) ? $this->context->evaluator->evaluate($base->args[1]->value, $environment) : null;
            $this->context->addOperation(TableOperation::addIndex(
                $table,
                new DeclaredIndex($columns, name: is_string($name) && $name !== '' ? $name : null),
                $base->getStartLine(),
            ));
        }
    }

    /** @param  list<Node\Expr\MethodCall>  $calls @param  list<string>  $columns @param  array<string, mixed>  $environment */
    private function processColumnModifiers(string $table, array $calls, array $columns, array $environment): void
    {
        $indexModifiers = [];

        foreach ($calls as $modifier) {
            $modifierName = StaticExpressionEvaluator::callName($modifier);
            if ($modifierName === null) {
                continue;
            }

            $type = $this->indexType($modifierName);
            if ($type !== null) {
                $indexModifiers[$type->value] = $modifier;

                continue;
            }

            $this->reportUnsupportedModifierIfAny($table, $modifier, $modifierName);
        }

        // Laravel materializes at most one fluent index command per column and
        // uses this fixed priority, regardless of the chain's call order.
        foreach ([DeclaredIndexType::PRIMARY, DeclaredIndexType::UNIQUE, DeclaredIndexType::INDEX] as $type) {
            $modifier = $indexModifiers[$type->value] ?? null;
            if (! $modifier instanceof Node\Expr\MethodCall) {
                continue;
            }

            $name = isset($modifier->args[0]) ? $this->context->evaluator->evaluate($modifier->args[0]->value, $environment) : null;
            $this->context->addOperation(TableOperation::addIndex(
                $table,
                new DeclaredIndex($columns, $type, is_string($name) && $name !== '' ? $name : null),
                $modifier->getStartLine(),
            ));

            break;
        }
    }

    private function reportUnsupportedModifierIfAny(string $table, Node\Expr\MethodCall $modifier, string $modifierName): void
    {
        $known = in_array($modifierName, array_map(strtolower(...), self::COLUMN_MODIFIERS), true)
            || in_array($modifierName, array_map(strtolower(...), self::OUT_OF_SCOPE_BLUEPRINT_METHODS), true);

        if (! $known) {
            $this->context->reportUnreadable(
                $modifier,
                sprintf('Unsupported Blueprint chain method [%s] on table [%s].', $modifierName, $table),
            );
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processDirectIndex(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): bool {
        $type = $this->indexType($method);
        if ($type === null) {
            return false;
        }

        $columns = $this->context->evaluator->stringList($call->args[0]->value ?? null, $environment);
        if ($columns === []) {
            $this->context->reportUnreadable($call, sprintf('Blueprint::%s columns on table [%s] are runtime-dependent.', $method, $table));

            return true;
        }

        $name = isset($call->args[1]) ? $this->context->evaluator->evaluate($call->args[1]->value, $environment) : null;
        $this->context->addOperation(TableOperation::addIndex(
            $table,
            new DeclaredIndex($columns, $type, is_string($name) && $name !== '' ? $name : null),
            $call->getStartLine(),
        ));

        return true;
    }

    /** @param  array<string, mixed>  $environment */
    private function processDrop(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): bool {
        return match ($method) {
            'dropcolumn' => $this->processDropColumn($table, $call, $environment),
            'dropconstrainedforeignid' => $this->processDropConstrainedForeignId($table, $call, $environment),
            'renamecolumn' => $this->processRenameColumn($table, $call, $environment),
            'renameindex' => $this->processRenameIndex($table, $call, $environment),
            'dropindex', 'dropunique', 'dropprimary' => $this->processDropIndex($table, $call, $method, $environment),
            default => $this->processShortcutDrop($table, $call, $method, $environment),
        };
    }

    /** @param  array<string, mixed>  $environment */
    private function processDropColumn(string $table, Node\Expr\MethodCall $call, array $environment): bool
    {
        if ($call->args === []) {
            $this->context->reportUnreadable($call, sprintf('Blueprint::dropColumn target on table [%s] is missing.', $table));

            return true;
        }

        foreach ($call->args as $argument) {
            $columns = $this->context->evaluator->stringList($argument->value, $environment);
            $value = $this->context->evaluator->evaluate($argument->value, $environment);
            if ($columns === [] && $value instanceof FilteredStringCandidates) {
                $columns = $value->values;
            }

            if ($columns === []) {
                $this->context->reportUnreadable($argument, sprintf('Blueprint::dropColumn target on table [%s] is runtime-dependent.', $table));

                continue;
            }

            foreach ($columns as $column) {
                $this->context->addOperation(TableOperation::dropColumn($table, $column, $call->getStartLine()));
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $environment */
    private function processDropConstrainedForeignId(string $table, Node\Expr\MethodCall $call, array $environment): bool
    {
        $column = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        if (is_string($column) && $column !== '') {
            $this->context->addOperation(TableOperation::dropColumn($table, $column, $call->getStartLine()));
        } else {
            $this->context->reportUnreadable($call, sprintf('Blueprint::dropConstrainedForeignId column on table [%s] is runtime-dependent.', $table));
        }

        return true;
    }

    /** @param  array<string, mixed>  $environment */
    private function processRenameColumn(string $table, Node\Expr\MethodCall $call, array $environment): bool
    {
        $from = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        $to = isset($call->args[1]) ? $this->context->evaluator->evaluate($call->args[1]->value, $environment) : null;
        if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
            $this->context->addOperation(TableOperation::renameColumn($table, $from, $to, $call->getStartLine()));
        } else {
            $this->context->reportUnreadable($call, sprintf('Blueprint::renameColumn names on table [%s] are runtime-dependent.', $table));
        }

        return true;
    }

    /** @param  array<string, mixed>  $environment */
    private function processRenameIndex(string $table, Node\Expr\MethodCall $call, array $environment): bool
    {
        $from = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        $to = isset($call->args[1]) ? $this->context->evaluator->evaluate($call->args[1]->value, $environment) : null;
        if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
            $this->context->addOperation(TableOperation::renameIndex($table, $from, $to, $call->getStartLine()));
        } else {
            $this->context->reportUnreadable($call, sprintf('Blueprint::renameIndex names on table [%s] are runtime-dependent.', $table));
        }

        return true;
    }

    /** @param  array<string, mixed>  $environment */
    private function processDropIndex(string $table, Node\Expr\MethodCall $call, string $method, array $environment): bool
    {
        $dropTypes = [
            'dropindex' => DeclaredIndexType::INDEX,
            'dropunique' => DeclaredIndexType::UNIQUE,
            'dropprimary' => DeclaredIndexType::PRIMARY,
        ];

        $value = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        if (is_string($value) && $value !== '') {
            $this->context->addOperation(TableOperation::dropIndexNamed($table, $value, $call->getStartLine()));
        } elseif (is_array($value)) {
            $columns = array_values(array_filter($value, is_string(...)));
            if ($columns !== []) {
                $this->context->addOperation(TableOperation::dropIndex(
                    $table,
                    new DeclaredIndex($columns, $dropTypes[$method]),
                    $call->getStartLine(),
                ));
            }
        } elseif ($method === 'dropprimary') {
            $this->context->addOperation(TableOperation::dropIndexNamed($table, strtolower($table.'_primary'), $call->getStartLine()));
        } else {
            $this->context->reportUnreadable($call, sprintf('Blueprint::%s target on table [%s] is runtime-dependent.', $method, $table));
        }

        return true;
    }

    /** @param  array<string, mixed>  $environment */
    private function processShortcutDrop(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): bool {
        $softDeleteColumn = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        $morphName = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;

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
            $this->context->reportUnreadable($call, sprintf('Blueprint::%s target on table [%s] is runtime-dependent.', $method, $table));

            return true;
        }

        foreach ($columns as $column) {
            $this->context->addOperation(TableOperation::dropColumn($table, $column, $call->getStartLine()));
        }

        return true;
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
