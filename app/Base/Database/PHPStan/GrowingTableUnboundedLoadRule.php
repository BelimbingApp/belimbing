<?php

namespace App\Base\Database\PHPStan;

use App\Base\Database\Attributes\PartitionedBy;
use App\Base\Database\Contracts\GrowingTable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Refuse loading every row of a growing table into PHP.
 *
 * A model implementing GrowingTable (logs, history, runs, audit, events)
 * has no natural upper bound, so ->get(), ->all(), ->pluck(), ->getModels()
 * and ::all() on it are reported unless the same call chain visibly bounds
 * the result: limit/take/forPage*, whereKey (ids already reduced by an
 * aggregate), a groupBy/distinct reduction, a fromSub ranked subquery, an
 * equality where() on a column the model declares in #[PartitionedBy], a
 * whereBelongsTo() a growing parent, or a relation hanging off a growing
 * parent ($run->events()). paginate(), chunk(), lazy() and cursor() are
 * different methods and never reported.
 *
 * Bounds applied on another statement ($query->limit(10); $query->get())
 * are invisible to a chain walk; suppress such a call inline with a
 * phpstan-ignore comment naming self::IDENTIFIER and why it is bounded.
 * Query-builder access (DB::table()) and lazy relation properties are not
 * covered; the runtime hydration guard is the backstop for those.
 *
 * @implements Rule<CallLike>
 */
final class GrowingTableUnboundedLoadRule implements Rule
{
    public const IDENTIFIER = 'blb.growingTableUnboundedLoad';

    private const LOADING_METHODS = ['get', 'all', 'pluck', 'getModels'];

    /** Once one of these has run, later calls in the chain act on the result, not the query. */
    private const TERMINAL_METHODS = [
        ...self::LOADING_METHODS,
        'first',
        'firstOrFail',
        'firstOr',
        'sole',
        'find',
        'findMany',
        'findOrFail',
        'paginate',
        'simplePaginate',
        'cursorPaginate',
        'cursor',
        'lazy',
        'lazyById',
        'lazyByIdDesc',
        'chunk',
        'chunkById',
        'each',
        'count',
        'exists',
        'value',
    ];

    private const BOUNDING_METHODS = [
        'limit',
        'take',
        'forPage',
        'forPageAfterId',
        'forPageBeforeId',
        'whereKey',
        'groupBy',
        'groupByRaw',
        'distinct',
        'fromSub',
    ];

    /** @var array<class-string, list<string>> */
    private array $partitions = [];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall) {
            return $this->processStaticCall($node, $scope);
        }

        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node, $scope);
        }

        return [];
    }

    /** @return list<IdentifierRuleError> */
    private function processStaticCall(StaticCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! in_array($node->name->toString(), self::LOADING_METHODS, true)) {
            return [];
        }

        $model = $this->growingModelNamedBy($node, $scope);
        if ($model === null) {
            return [];
        }

        return [$this->error($model, '::'.$node->name->toString().'()', $node->getStartLine())];
    }

    /** @return list<IdentifierRuleError> */
    private function processMethodCall(MethodCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! in_array($node->name->toString(), self::LOADING_METHODS, true)) {
            return [];
        }

        [$calls, $root] = $this->walkChain($node->var);
        $chain = array_map(static fn (MethodCall $call): string => $call->name->toString(), $calls);

        if (array_intersect($chain, self::BOUNDING_METHODS) !== []
            || array_intersect($chain, self::TERMINAL_METHODS) !== []) {
            return [];
        }

        $model = $this->growingModelOfQuery($scope->getType($node->var))
            ?? ($root instanceof StaticCall ? $this->growingModelNamedBy($root, $scope) : null);

        if ($model === null
            || $this->hangsOffGrowingParent([$node->var, ...$calls], $scope)
            || $this->filtersToOnePartition($model, $calls, $scope)) {
            return [];
        }

        return [$this->error($model, '->'.$node->name->toString().'()', $node->getStartLine())];
    }

    /**
     * Named method calls between the receiver and the load, innermost first,
     * and the expression the chain hangs off.
     *
     * @return array{0: list<MethodCall>, 1: Expr}
     */
    private function walkChain(Expr $expr): array
    {
        $calls = [];

        while ($expr instanceof MethodCall) {
            if ($expr->name instanceof Identifier) {
                $calls[] = $expr;
            }

            $expr = $expr->var;
        }

        return [$calls, $expr];
    }

    /**
     * $run->events()->get(): a relation declared on a growing parent is one
     * parent's slice. Checked on every link of the chain, because a forwarded
     * builder call ($run->events()->where(...)) no longer types as the relation.
     *
     * @param  list<Expr>  $links
     */
    private function hangsOffGrowingParent(array $links, Scope $scope): bool
    {
        foreach ($links as $link) {
            foreach ($scope->getType($link)->getTemplateType(Relation::class, 'TDeclaringModel')->getObjectClassNames() as $class) {
                if ($this->isGrowingTable($class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * where('run_id', $id) or where('run_id', '=', $id) on a declared
     * partition column, or whereBelongsTo() a growing parent.
     *
     * @param  list<MethodCall>  $calls
     */
    private function filtersToOnePartition(string $model, array $calls, Scope $scope): bool
    {
        foreach ($calls as $call) {
            $method = $call->name->toString();
            $args = $call->getArgs();

            if ($method === 'whereBelongsTo' && isset($args[0])) {
                foreach ($scope->getType($args[0]->value)->getObjectClassNames() as $class) {
                    if ($this->isGrowingTable($class)) {
                        return true;
                    }
                }

                continue;
            }

            if ($method !== 'where' || ! isset($args[0]) || count($args) > 3) {
                continue;
            }

            if (count($args) === 3 && ! in_array('=', $this->constantStrings($args[1]->value, $scope), true)) {
                continue;
            }

            $columns = array_map(
                static fn (string $column): string => str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column,
                $this->constantStrings($args[0]->value, $scope),
            );

            if (array_intersect($columns, $this->partitions($model)) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function constantStrings(Expr $expr, Scope $scope): array
    {
        return array_map(
            static fn ($string): string => $string->getValue(),
            $scope->getType($expr)->getConstantStrings(),
        );
    }

    /**
     * @param  class-string  $model
     * @return list<string>
     */
    private function partitions(string $model): array
    {
        return $this->partitions[$model] ??= $this->declaredPartitions($this->reflectionProvider->getClass($model));
    }

    /** @return list<string> */
    private function declaredPartitions(ClassReflection $reflection): array
    {
        $columns = [];

        foreach ($reflection->getNativeReflection()->getAttributes(PartitionedBy::class) as $attribute) {
            foreach ($attribute->getArguments() as $column) {
                if (is_string($column)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    /** @return class-string|null */
    private function growingModelOfQuery(Type $type): ?string
    {
        $candidates = [
            ...$type->getTemplateType(EloquentBuilder::class, 'TModel')->getObjectClassNames(),
            ...$type->getTemplateType(Relation::class, 'TRelatedModel')->getObjectClassNames(),
        ];

        foreach ($candidates as $class) {
            if ($this->isGrowingTable($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Model::all(), Model::get(), Model::where(...) and the like resolve to
     * the class in source even where the static forwarding is untyped.
     *
     * @return class-string|null
     */
    private function growingModelNamedBy(StaticCall $node, Scope $scope): ?string
    {
        if (! $node->class instanceof Name) {
            return null;
        }

        $class = $scope->resolveName($node->class);

        return $this->isGrowingTable($class) ? $class : null;
    }

    private function isGrowingTable(string $class): bool
    {
        return $this->reflectionProvider->hasClass($class)
            && $this->reflectionProvider->getClass($class)->implementsInterface(GrowingTable::class);
    }

    private function error(string $model, string $call, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            '%s on growing table model [%s] loads every row; limit/take/forPage it, paginate, chunk, lazy or cursor it, or reduce it in the database first (groupBy aggregate, whereKey on aggregated ids).',
            $call,
            $model,
        ))
            ->identifier(self::IDENTIFIER)
            ->line($line)
            ->build();
    }
}
