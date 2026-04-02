<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools\Schema;

/**
 * Fluent builder for OpenAI function-calling JSON Schema.
 *
 * Replaces hand-crafted schema arrays with a validated, composable builder.
 * Produces the `parameters` object expected by OpenAI's function calling API.
 *
 * Usage:
 *   ToolSchemaBuilder::make()
 *       ->string('command', 'The bash command to execute')->required()
 *       ->integer('timeout', 'Timeout in seconds', min: 1, max: 300)
 *       ->boolean('background', 'Run in background')
 *       ->build();
 */
final class ToolSchemaBuilder
{
    /** @var array<string, array<string, mixed>> */
    private array $properties = [];

    /** @var list<string> */
    private array $required = [];

    /**
     * Tracks the last added property name for chaining ->required().
     */
    private ?string $lastProperty = null;

    private function __construct()
    {
        // Intentionally private: consumers create builders via make().
    }

    /**
     * Create a new schema builder instance.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Add a string property.
     *
     * @param  string  $name  Property name
     * @param  string  $description  Property description for the LLM
     * @param  list<string>|null  $enum  Optional enumerated allowed values
     */
    public function string(string $name, string $description, ?array $enum = null): self
    {
        $property = [
            'type' => 'string',
            'description' => $description,
        ];

        if ($enum !== null) {
            $property['enum'] = $enum;
        }

        return $this->addProperty($name, $property);
    }

    /**
     * Add an integer property.
     *
     * @param  string  $name  Property name
     * @param  string  $description  Property description for the LLM
     * @param  int|null  $min  Optional minimum value (inclusive)
     * @param  int|null  $max  Optional maximum value (inclusive)
     */
    public function integer(string $name, string $description, ?int $min = null, ?int $max = null): self
    {
        $property = [
            'type' => 'integer',
            'description' => $description,
        ];

        if ($min !== null) {
            $property['minimum'] = $min;
        }

        if ($max !== null) {
            $property['maximum'] = $max;
        }

        return $this->addProperty($name, $property);
    }

    /**
     * Add a boolean property.
     *
     * @param  string  $name  Property name
     * @param  string  $description  Property description for the LLM
     */
    public function boolean(string $name, string $description): self
    {
        return $this->addProperty($name, [
            'type' => 'boolean',
            'description' => $description,
        ]);
    }

    /**
     * Add an array property.
     *
     * @param  string  $name  Property name
     * @param  string  $description  Property description for the LLM
     * @param  array<string, mixed>  $items  Schema for array items
     */
    public function array(string $name, string $description, array $items = ['type' => 'string']): self
    {
        return $this->addProperty($name, [
            'type' => 'array',
            'description' => $description,
            'items' => $items,
        ]);
    }

    /**
     * Add a property with a oneOf schema (union type).
     *
     * @param  string  $name  Property name
     * @param  string  $description  Property description for the LLM
     * @param  list<array<string, mixed>>  $oneOf  List of possible schemas
     */
    public function oneOf(string $name, string $description, array $oneOf): self
    {
        return $this->addProperty($name, [
            'oneOf' => $oneOf,
            'description' => $description,
        ]);
    }

    /**
     * Mark the last added property as required.
     *
     * Must be called immediately after adding a property.
     */
    public function required(): self
    {
        if ($this->lastProperty !== null && ! in_array($this->lastProperty, $this->required, true)) {
            $this->required[] = $this->lastProperty;
        }

        return $this;
    }

    /**
     * Build the final JSON Schema object.
     *
     * @return array{type: string, properties: array<string, array<string, mixed>>, required?: list<string>}
     */
    public function build(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $this->properties === [] ? new \stdClass : $this->properties,
        ];

        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }

        return $schema;
    }

    /**
     * Add a property to the schema.
     *
     * @param  string  $name  Property name
     * @param  array<string, mixed>  $definition  Property schema definition
     */
    private function addProperty(string $name, array $definition): self
    {
        $this->properties[$name] = $definition;
        $this->lastProperty = $name;

        return $this;
    }
}
