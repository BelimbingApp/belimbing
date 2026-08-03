<?php

namespace App\Base\Database\Services\SchemaDrift;

/**
 * One source-declared index. Live comparison uses its ordered column list and
 * semantic kind; names remain available only to replay later drop/rename calls.
 */
final readonly class DeclaredIndex
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public array $columns,
        public DeclaredIndexType $type = DeclaredIndexType::INDEX,
        public ?string $name = null,
        public bool $compareByName = false,
    ) {}

    public function signature(): string
    {
        if ($this->compareByName && $this->name !== null) {
            return 'named:'.strtolower($this->name);
        }

        return $this->type->value.':'.implode(',', array_map(strtolower(...), $this->columns));
    }

    public function resolvedName(string $table): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return str_replace(
            ['-', '.'],
            '_',
            strtolower($table.'_'.implode('_', $this->columns).'_'.$this->type->value),
        );
    }

    public function withResolvedName(string $table): self
    {
        return new self($this->columns, $this->type, $this->resolvedName($table), $this->compareByName);
    }

    public function withName(string $name): self
    {
        return new self($this->columns, $this->type, $name, $this->compareByName);
    }

    public function withRenamedColumn(string $from, string $to): self
    {
        return new self(
            array_map(fn (string $column): string => strcasecmp($column, $from) === 0 ? $to : $column, $this->columns),
            $this->type,
            $this->name,
            $this->compareByName,
        );
    }
}
