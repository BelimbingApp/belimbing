<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Node;

/**
 * Evaluates AST expressions to static PHP values for schema-drift source
 * analysis. Supports literals, constants, variables (from a bounded
 * environment), arrays, concatenation, coalesce, ternary, string casts,
 * and finite collect() chains.
 */
final class StaticExpressionEvaluator
{
    /** @var array<string, mixed> */
    private array $constants;

    /** @param  array<string, mixed>  $constants */
    public function __construct(array $constants = [])
    {
        $this->constants = $constants;
    }

    /** @param  array<string, mixed>  $constants */
    public function setConstants(array $constants): void
    {
        $this->constants = $constants;
    }

    public function evaluate(Node\Expr $expression, array $environment): mixed
    {
        if ($expression instanceof Node\Expr\MethodCall) {
            return $this->evaluateCollectChain($expression, $environment);
        }

        return $this->evaluateLiteral($expression, $environment);
    }

    private function evaluateLiteral(Node\Expr $expression, array $environment): mixed
    {
        return match (true) {
            $expression instanceof Node\Scalar\String_,
            $expression instanceof Node\Scalar\LNumber,
            $expression instanceof Node\Scalar\DNumber => $expression->value,

            $expression instanceof Node\Expr\ConstFetch => match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => null,
            },

            $expression instanceof Node\Expr\Variable && is_string($expression->name) => $environment[$expression->name] ?? null,

            $expression instanceof Node\Expr\ClassConstFetch
                && $expression->name instanceof Node\Identifier
                && $expression->class instanceof Node\Name
                && in_array(strtolower($expression->class->toString()), ['self', 'static'], true) => $this->constants[$expression->name->toString()] ?? null,

            $expression instanceof Node\Expr\Array_ => $this->evaluateArray($expression, $environment),
            $expression instanceof Node\Expr\BinaryOp\Concat => $this->evaluateConcat($expression, $environment),
            $expression instanceof Node\Expr\BinaryOp\Coalesce => $this->evaluateCoalesce($expression, $environment),
            $expression instanceof Node\Expr\Ternary => $this->evaluateTernary($expression, $environment),
            $expression instanceof Node\Expr\Cast\String_ => $this->evaluateStringCast($expression, $environment),
            default => null,
        };
    }

    /** @return list<string>|array<int|string, mixed> */
    private function evaluateArray(Node\Expr\Array_ $expression, array $environment): array
    {
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

    private function evaluateConcat(Node\Expr\BinaryOp\Concat $expression, array $environment): ?string
    {
        $left = $this->evaluate($expression->left, $environment);
        $right = $this->evaluate($expression->right, $environment);

        return (is_string($left) || is_numeric($left)) && (is_string($right) || is_numeric($right))
            ? (string) $left.(string) $right
            : null;
    }

    private function evaluateCoalesce(Node\Expr\BinaryOp\Coalesce $expression, array $environment): mixed
    {
        return $this->evaluate($expression->left, $environment)
            ?? $this->evaluate($expression->right, $environment);
    }

    private function evaluateTernary(Node\Expr\Ternary $expression, array $environment): mixed
    {
        $if = $expression->if === null ? null : $this->evaluate($expression->if, $environment);
        $else = $this->evaluate($expression->else, $environment);

        return $if === $else ? $if : null;
    }

    private function evaluateStringCast(Node\Expr\Cast\String_ $expression, array $environment): ?string
    {
        $value = $this->evaluate($expression->expr, $environment);

        return is_scalar($value) ? (string) $value : null;
    }

    private function evaluateCollectChain(Node\Expr\MethodCall $expression, array $environment): mixed
    {
        $chain = self::methodChain($expression);
        $root = $chain['root'];

        if (! $root instanceof Node\Expr\FuncCall
            || ! $root->name instanceof Node\Name
            || strtolower($root->name->toString()) !== 'collect'
            || ! isset($root->args[0])) {
            return null;
        }

        $values = $this->evaluate($root->args[0]->value, $environment);

        return match (true) {
            ! is_array($values) => null,
            default => $this->evaluateFilteredStrings($chain['calls'], $values),
        };
    }

    /** @param  list<Node\Expr\MethodCall>  $calls @param  array<mixed>  $values */
    private function evaluateFilteredStrings(array $calls, array $values): FilteredStringCandidates|array|null
    {
        $methods = array_map(self::callName(...), $calls);
        if (in_array(null, $methods, true) || array_diff($methods, ['filter', 'values', 'all']) !== []) {
            return null;
        }

        $strings = array_values(array_filter(
            $values,
            fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        return in_array('filter', $methods, true)
            ? new FilteredStringCandidates($strings)
            : $strings;
    }

    /** @param  array<string, mixed>  $environment @return list<string> */
    public function stringList(?Node\Expr $expression, array $environment): array
    {
        if ($expression === null) {
            return [];
        }

        $value = $this->evaluate($expression, $environment);

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return is_array($value)
            ? array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''))
            : [];
    }

    /** @param  array<string, mixed>  $environment */
    public function optionalStringArgument(
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

    /** @param  array<int|string, mixed>  $arguments @return array<string, mixed> */
    public function methodEnvironment(Node\Stmt\ClassMethod $method, array $arguments): array
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

    /** @param  list<Node\Arg>  $arguments @param  array<string, mixed>  $environment @return array<int|string, mixed> */
    public function resolvedCallArguments(array $arguments, array $environment): array
    {
        $resolved = [];
        foreach ($arguments as $position => $argument) {
            $key = $argument->name?->toString() ?? $position;
            $resolved[$key] = $this->evaluate($argument->value, $environment);
        }

        return $resolved;
    }

    /** @return array{root: Node\Expr, calls: list<Node\Expr\MethodCall>} */
    public static function methodChain(Node\Expr\MethodCall $method): array
    {
        $calls = [];
        $root = $method;

        while ($root instanceof Node\Expr\MethodCall) {
            array_unshift($calls, $root);
            $root = $root->var;
        }

        return ['root' => $root, 'calls' => $calls];
    }

    public static function callName(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null;
    }

    public static function staticClassName(Node\Expr\StaticCall $call): string
    {
        if (! $call->class instanceof Node\Name) {
            return '';
        }

        $resolved = $call->class->getAttribute('resolvedName');
        $name = $resolved instanceof Node\Name ? $resolved->toString() : $call->class->toString();
        $segments = explode('\\', $name);

        return strtolower((string) end($segments));
    }
}
