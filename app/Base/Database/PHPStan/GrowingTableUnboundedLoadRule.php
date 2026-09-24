<?php

namespace App\Base\Database\PHPStan;

use App\Base\Database\Contracts\GrowingTable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Refuse loading every row of a growing table into PHP.
 *
 * A model implementing GrowingTable (logs, history, runs, audit, events)
 * has no natural upper bound, so ->get(), ->all(), ->pluck(), ->getModels()
 * and ::all() on it are reported unless the same call chain limits the
 * result with limit/take/forPage* or whereKey. paginate(), chunk(), lazy() and cursor()
 * are different methods and never reported.
 *
 * Any other bound (a bound applied on another statement, one parent's rows,
 * an aggregate that reduces the result) is invisible to the rule; suppress
 * such a call inline with a phpstan-ignore comment naming self::IDENTIFIER
 * and why it is bounded, so each exception is a reviewed claim.
 * Query-builder access (DB::table()) and lazy relation properties are not
 * covered; the runtime hydration guard is the backstop for those.
 *
 * @implements Rule<CallLike>
 */
final class GrowingTableUnboundedLoadRule implements Rule
{
    public const IDENTIFIER = 'blb.growingTableUnboundedLoad';

    private const LOADING_METHODS = ['get', 'all', 'pluck', 'getModels'];

    /** Once one of these has run, later calls act on its result or start a new query from it. */
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
    ];

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

        [$chain, $root] = $this->walkQuery($node->var);

        if (array_intersect($chain, self::BOUNDING_METHODS) !== []) {
            return [];
        }

        $model = $this->growingModelOfQuery($scope->getType($node->var))
            ?? ($root !== null ? $this->growingModelNamedBy($root, $scope) : null);

        if ($model === null) {
            return [];
        }

        return [$this->error($model, '->'.$node->name->toString().'()', $node->getStartLine())];
    }

    /**
     * Names of the calls that build the query being loaded, innermost first,
     * walking back from the load to the nearest terminal call. A terminal ends
     * the query: anything after it ($task->find(1)->runs()) starts a new one.
     * The static call the query starts from, when it starts from one, is
     * returned so its model can be resolved where the forwarding is untyped.
     *
     * @return array{0: list<string>, 1: StaticCall|null}
     */
    private function walkQuery(Expr $expr): array
    {
        $chain = [];

        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall) {
            $name = $expr->name instanceof Identifier ? $expr->name->toString() : null;

            if ($name !== null && in_array($name, self::TERMINAL_METHODS, true)) {
                return [$chain, null];
            }

            if ($name !== null) {
                $chain[] = $name;
            }

            if ($expr instanceof StaticCall) {
                return [$chain, $expr];
            }

            $expr = $expr->var;
        }

        return [$chain, null];
    }

    /** @return class-string|null */
    private function growingModelOfQuery(Type $type): ?string
    {
        $type = TypeCombinator::removeNull($type);
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
            '%s on growing table model [%s] loads every row; limit/take/forPage it, restrict it to known ids with whereKey, paginate, chunk, lazy or cursor it, or suppress it inline with the reason it is bounded.',
            $call,
            $model,
        ))
            ->identifier(self::IDENTIFIER)
            ->line($line)
            ->build();
    }
}
