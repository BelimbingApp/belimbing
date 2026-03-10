<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\Foundation\Exceptions\BlbDataContractException;

/**
 * Stateless query engine for filtering model catalog records.
 *
 * Supports boolean expressions with AND/OR operators over normalized catalog
 * fields, and returns flattened model entries suitable for orchestration and
 * UI list rendering.
 */
class ModelCatalogQueryService
{
    public function __construct(
        private readonly ModelCatalogService $modelCatalog,
    ) {}

    /**
     * Query models catalog using boolean expressions with AND/OR operators.
     *
     * Supported fields:
     * - provider, model, name, family
     * - reasoning, tools, open_weights
     * - input, output, category, region
     *
     * Example:
     *   reasoning:true AND input:text AND (family:gpt OR family:claude)
     *
     * @return list<array{provider: string, provider_display_name: string, model: string, name: string, family: string, reasoning: bool, tools: bool, open_weights: bool, input: list<string>, output: list<string>, category: list<string>, region: list<string>}>
     */
    public function query(string $expression, int $limit = 20): array
    {
        $normalizedExpression = trim($expression);
        if ($normalizedExpression === '') {
            return [];
        }

        $rpn = $this->toRpn($normalizedExpression);
        $matches = [];

        foreach ($this->catalogRecords() as $record) {
            if ($this->matchesExpression($record, $rpn)) {
                $matches[] = $record;
            }
        }

        usort($matches, fn (array $left, array $right): int => strcmp($left['model'], $right['model']));

        return array_slice($matches, 0, $limit);
    }

    /**
     * @return list<array{provider: string, provider_display_name: string, model: string, name: string, family: string, reasoning: bool, tools: bool, open_weights: bool, input: list<string>, output: list<string>, category: list<string>, region: list<string>}>
     */
    private function catalogRecords(): array
    {
        $providers = $this->modelCatalog->getProviders();
        $records = [];

        foreach ($providers as $providerId => $provider) {
            $models = $this->modelCatalog->getModels((string) $providerId);
            $categories = $this->normalizeStringList($provider['category'] ?? []);
            $regions = $this->normalizeStringList($provider['region'] ?? []);

            foreach ($models as $modelId => $model) {
                $inputModalities = $this->normalizeStringList($model['modalities']['input'] ?? []);
                $outputModalities = $this->normalizeStringList($model['modalities']['output'] ?? []);

                $records[] = [
                    'provider' => (string) $providerId,
                    'provider_display_name' => (string) ($provider['display_name'] ?? $providerId),
                    'model' => (string) ($model['id'] ?? $modelId),
                    'name' => (string) ($model['name'] ?? $modelId),
                    'family' => (string) ($model['family'] ?? ''),
                    'reasoning' => (bool) ($model['reasoning'] ?? false),
                    'tools' => (bool) ($model['tool_call'] ?? false),
                    'open_weights' => (bool) ($model['open_weights'] ?? false),
                    'input' => $inputModalities,
                    'output' => $outputModalities,
                    'category' => $categories,
                    'region' => $regions,
                ];
            }
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_map(fn (mixed $item): string => mb_strtolower((string) $item), $value);

        return array_values(array_filter($items, fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $expression): array
    {
        preg_match_all('/\(|\)|\bAND\b|\bOR\b|[^\s()]+/i', $expression, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @return list<string>
     */
    private function toRpn(string $expression): array
    {
        $tokens = $this->tokenize($expression);
        $output = [];
        $operators = [];

        foreach ($tokens as $token) {
            $upper = mb_strtoupper($token);

            if ($upper === 'AND' || $upper === 'OR') {
                $this->pushOperator($upper, $operators, $output);

                continue;
            }

            if ($token === '(') {
                $operators[] = $token;

                continue;
            }

            if ($token === ')') {
                $this->closeParenthesizedExpression($expression, $operators, $output);

                continue;
            }

            $this->assertPredicateToken($token, $expression);
            $output[] = $token;
        }

        $this->drainOperators($expression, $operators, $output);

        return $output;
    }

    /**
     * @param  list<string>  $operators
     * @param  list<string>  $output
     */
    private function pushOperator(string $operator, array &$operators, array &$output): void
    {
        while ($this->shouldDrainOperatorStack($operators, $operator)) {
            $output[] = array_pop($operators);
        }

        $operators[] = $operator;
    }

    /**
     * @param  list<string>  $operators
     */
    private function shouldDrainOperatorStack(array $operators, string $operator): bool
    {
        $topOperator = end($operators);

        return $operators !== []
            && $this->isOperator($topOperator)
            && $this->precedence($topOperator) >= $this->precedence($operator);
    }

    /**
     * @param  list<string>  $operators
     * @param  list<string>  $output
     */
    private function closeParenthesizedExpression(string $expression, array &$operators, array &$output): void
    {
        while ($operators !== [] && end($operators) !== '(') {
            $output[] = array_pop($operators);
        }

        if ($operators === [] || end($operators) !== '(') {
            $this->throwMismatchedParentheses($expression);
        }

        array_pop($operators);
    }

    private function assertPredicateToken(string $token, string $expression): void
    {
        if (! str_contains($token, ':')) {
            throw new BlbDataContractException('Invalid /models filter token. Expected field:value.', context: [
                'token' => $token,
                'expression' => $expression,
            ]);
        }
    }

    /**
     * @param  list<string>  $operators
     * @param  list<string>  $output
     */
    private function drainOperators(string $expression, array &$operators, array &$output): void
    {
        while ($operators !== []) {
            $operator = array_pop($operators);

            if ($operator === '(' || $operator === ')') {
                $this->throwMismatchedParentheses($expression);
            }

            $output[] = $operator;
        }
    }

    private function throwMismatchedParentheses(string $expression): never
    {
        throw new BlbDataContractException('Invalid /models filter: mismatched parentheses.', context: [
            'expression' => $expression,
        ]);
    }

    private function isOperator(string|false $token): bool
    {
        return $token === 'AND' || $token === 'OR';
    }

    private function precedence(string|false $operator): int
    {
        return $operator === 'AND' ? 2 : 1;
    }

    /**
     * @param  array{provider: string, provider_display_name: string, model: string, name: string, family: string, reasoning: bool, tools: bool, open_weights: bool, input: list<string>, output: list<string>, category: list<string>, region: list<string>}  $record
     * @param  list<string>  $rpn
     */
    private function matchesExpression(array $record, array $rpn): bool
    {
        $stack = [];

        foreach ($rpn as $token) {
            if ($token === 'AND' || $token === 'OR') {
                if (count($stack) < 2) {
                    return false;
                }

                $right = (bool) array_pop($stack);
                $left = (bool) array_pop($stack);
                $stack[] = $token === 'AND' ? ($left && $right) : ($left || $right);

                continue;
            }

            $stack[] = $this->matchesPredicate($record, $token);
        }

        return count($stack) === 1 && (bool) $stack[0];
    }

    /**
     * @param  array{provider: string, provider_display_name: string, model: string, name: string, family: string, reasoning: bool, tools: bool, open_weights: bool, input: list<string>, output: list<string>, category: list<string>, region: list<string>}  $record
     */
    private function matchesPredicate(array $record, string $predicate): bool
    {
        [$field, $value] = explode(':', $predicate, 2);

        $field = mb_strtolower(trim($field));
        $value = mb_strtolower(trim($value));

        return match ($field) {
            'provider' => str_contains(mb_strtolower($record['provider']), $value)
                || str_contains(mb_strtolower($record['provider_display_name']), $value),
            'model' => str_contains(mb_strtolower($record['model']), $value),
            'name' => str_contains(mb_strtolower($record['name']), $value),
            'family' => str_contains(mb_strtolower($record['family']), $value),
            'reasoning' => $record['reasoning'] === $this->parseBoolean($value),
            'tools', 'tool_call' => $record['tools'] === $this->parseBoolean($value),
            'open_weights' => $record['open_weights'] === $this->parseBoolean($value),
            'input' => in_array($value, $record['input'], true),
            'output' => in_array($value, $record['output'], true),
            'category' => in_array($value, $record['category'], true),
            'region' => in_array($value, $record['region'], true),
            default => false,
        };
    }

    private function parseBoolean(string $value): bool
    {
        return in_array($value, ['1', 'true', 'yes'], true);
    }
}
