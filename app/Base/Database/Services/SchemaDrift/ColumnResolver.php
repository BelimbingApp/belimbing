<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Node;

/**
 * Resolves the column names produced by a Blueprint method call,
 * covering shortcut methods, explicit column methods, and foreignIdFor.
 */
final class ColumnResolver
{
    /** @var list<string> */
    private const COLUMN_METHODS = [
        'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime', 'dateTimeTz',
        'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignIdFor', 'foreignUlid',
        'foreignUuid', 'geometry', 'geography', 'integer', 'ipAddress', 'json', 'jsonb',
        'longText', 'macAddress', 'mediumInteger', 'mediumText', 'set', 'smallInteger',
        'string', 'text', 'time', 'timeTz', 'timestamp', 'timestampTz', 'tinyInteger',
        'unsignedBigInteger', 'unsignedInteger', 'unsignedMediumInteger',
        'unsignedSmallInteger', 'unsignedTinyInteger', 'ulid', 'uuid', 'vector', 'year',
    ];

    public function __construct(
        private readonly ParserContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>|null
     */
    public function columnsAddedBy(
        string $table,
        Node\Expr\MethodCall $call,
        string $method,
        array $environment,
    ): ?array {
        $shortcut = $this->shortcutColumns($call, $method, $environment);

        if ($shortcut !== null) {
            if ($shortcut === [] || in_array(null, $shortcut, true)) {
                $this->context->reportUnreadable($call, sprintf('Blueprint::%s column on table [%s] is runtime-dependent.', $method, $table));

                return [];
            }

            return array_values(array_filter($shortcut, is_string(...)));
        }

        return $this->explicitColumns($table, $call, $method, $environment);
    }

    /** @param  array<string, mixed>  $environment @return list<string|null>|null */
    private function shortcutColumns(Node\Expr\MethodCall $call, string $method, array $environment): ?array
    {
        return match ($method) {
            'id' => [$this->context->evaluator->optionalStringArgument($call, 0, $environment, 'id')],
            'timestamps', 'timestampstz', 'nullabletimestamps' => ['created_at', 'updated_at'],
            'softdeletes', 'softdeletestz' => [$this->context->evaluator->optionalStringArgument($call, 0, $environment, 'deleted_at')],
            'remembertoken' => ['remember_token'],
            'morphs', 'nullablemorphs', 'uuidmorphs', 'nullableuuidmorphs', 'ulidmorphs', 'nullableulidmorphs' => ($name = $this->context->evaluator->optionalStringArgument($call, 0, $environment)) === null ? [] : [$name.'_type', $name.'_id'],
            default => null,
        };
    }

    /** @param  array<string, mixed>  $environment @return list<string>|null */
    private function explicitColumns(string $table, Node\Expr\MethodCall $call, string $method, array $environment): ?array
    {
        if ($method === 'foreignidfor') {
            return $this->foreignIdForColumns($table, $call, $environment);
        }

        $isIncrementing = in_array($method, ['bigincrements', 'increments', 'mediumincrements', 'smallincrements', 'tinyincrements'], true);
        $isColumnMethod = in_array($method, array_map(strtolower(...), self::COLUMN_METHODS), true);
        if (! $isIncrementing && ! $isColumnMethod) {
            return null;
        }

        $column = isset($call->args[0]) ? $this->context->evaluator->evaluate($call->args[0]->value, $environment) : null;
        $valid = is_string($column) && $column !== '';
        if (! $valid) {
            $this->context->reportUnreadable($call, sprintf('Blueprint::%s column on table [%s] is runtime-dependent.', $method, $table));
        }

        return $valid ? [$column] : [];
    }

    /** @param  array<string, mixed>  $environment @return list<string> */
    private function foreignIdForColumns(string $table, Node\Expr\MethodCall $call, array $environment): array
    {
        $column = isset($call->args[1]) ? $this->context->evaluator->evaluate($call->args[1]->value, $environment) : null;
        if (! is_string($column) || $column === '') {
            $this->context->reportUnreadable($call, sprintf('Blueprint::foreignIdFor column on table [%s] requires runtime model metadata.', $table));

            return [];
        }

        return [$column];
    }
}
