<?php

namespace App\Base\Settings\DTO;

use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use App\Base\Settings\Exceptions\InvalidSettingScopeException;
use App\Base\Settings\Exceptions\InvalidSettingValueException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class SettingDefinition
{
    private const array SUPPORTED_TYPES = [
        'array',
        'boolean',
        'float',
        'integer',
        'mixed',
        'string',
    ];

    private const array SUPPORTED_SCOPES = [
        'company',
        'global',
        'user',
    ];

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $rules
     */
    private function __construct(
        public string $key,
        public string $type,
        public array $scopes,
        public mixed $default,
        public bool $nullable,
        public bool $encrypted,
        public array $rules,
        public ?string $label,
        public ?string $help,
        public string $owner,
        public ?string $editable,
        public ?string $capability,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(string $key, array $attributes): self
    {
        $type = $attributes['type'] ?? null;
        $scopes = $attributes['scopes'] ?? null;
        $rules = $attributes['rules'] ?? [];
        $label = $attributes['label'] ?? null;
        $help = $attributes['help'] ?? null;
        $owner = $attributes['owner'] ?? null;
        $editable = $attributes['editable'] ?? null;
        $capability = $attributes['capability'] ?? null;

        if ($key === '') {
            throw new InvalidSettingDefinitionException('Setting definition keys cannot be empty.');
        }

        if (! is_string($type) || ! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] must declare a supported type.",
            );
        }

        if (! is_array($scopes) || ! array_is_list($scopes) || $scopes === []) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] must declare at least one scope.",
            );
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, self::SUPPORTED_SCOPES, true)) {
                $scopeName = is_scalar($scope) ? (string) $scope : get_debug_type($scope);

                throw new InvalidSettingDefinitionException(
                    "Setting [{$key}] declares unsupported scope [{$scopeName}].",
                );
            }
        }

        if (count($scopes) !== count(array_unique($scopes))) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] cannot declare the same scope more than once.",
            );
        }

        if (! array_key_exists('default', $attributes)) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] must declare a code default.",
            );
        }

        foreach (['nullable', 'encrypted'] as $booleanAttribute) {
            if (array_key_exists($booleanAttribute, $attributes)
                && ! is_bool($attributes[$booleanAttribute])) {
                throw new InvalidSettingDefinitionException(
                    "Setting [{$key}] {$booleanAttribute} must be boolean when declared.",
                );
            }
        }

        if (! is_array($rules) || ! array_is_list($rules) || array_filter($rules, 'is_string') !== $rules) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] validation rules must be a list of strings.",
            );
        }

        foreach (['label' => $label, 'help' => $help] as $field => $value) {
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw new InvalidSettingDefinitionException(
                    "Setting [{$key}] {$field} must be a non-empty string when declared.",
                );
            }
        }

        if (! is_string($owner) || trim($owner) === '') {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] must declare its module owner.",
            );
        }

        foreach (['editable' => $editable, 'capability' => $capability] as $field => $value) {
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw new InvalidSettingDefinitionException(
                    "Setting [{$key}] {$field} must be a non-empty string when declared.",
                );
            }
        }

        if ($editable !== null && ($label === null || $help === null)) {
            throw new InvalidSettingDefinitionException(
                "Editable setting [{$key}] must declare label and help metadata.",
            );
        }

        $definition = new self(
            key: $key,
            type: $type,
            scopes: $scopes,
            default: $attributes['default'],
            nullable: (bool) ($attributes['nullable'] ?? false),
            encrypted: (bool) ($attributes['encrypted'] ?? false),
            rules: $rules,
            label: $label,
            help: $help,
            owner: $owner,
            editable: $editable,
            capability: $capability,
        );

        if (! $definition->accepts($definition->default)) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] has a default incompatible with its declared type.",
            );
        }

        if ($validationError = $definition->validationError($definition->default)) {
            throw new InvalidSettingDefinitionException(
                "Setting [{$key}] has a default that fails its declared validation: {$validationError}",
            );
        }

        return $definition;
    }

    public function allowsScope(?Scope $scope): bool
    {
        return in_array($scope?->type->value ?? 'global', $this->scopes, true);
    }

    public function matches(string $key): bool
    {
        return Str::is($this->key, $key);
    }

    public function assertScopeAllowed(?Scope $scope): void
    {
        if ($this->allowsScope($scope)) {
            return;
        }

        $scopeName = $scope?->type->value ?? 'global';

        throw new InvalidSettingScopeException(
            "Setting [{$this->key}] does not allow [{$scopeName}] scope.",
        );
    }

    public function assertStorableValue(mixed $value): void
    {
        if ($value === null) {
            throw new InvalidSettingValueException(
                "Setting [{$this->key}] cannot store null; forget the override instead.",
            );
        }

        if (! $this->accepts($value)) {
            throw new InvalidSettingValueException(
                "Setting [{$this->key}] expects [{$this->type}], ".get_debug_type($value).' given.',
            );
        }

        if ($validationError = $this->validationError($value)) {
            throw new InvalidSettingValueException(
                "Setting [{$this->key}] failed validation: {$validationError}",
            );
        }
    }

    public function ruleParameter(string $rule): ?string
    {
        $prefix = $rule.':';

        foreach ($this->rules as $candidate) {
            if (str_starts_with($candidate, $prefix)) {
                return substr($candidate, strlen($prefix));
            }
        }

        return null;
    }

    private function accepts(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        return match ($this->type) {
            'array' => is_array($value),
            'boolean' => is_bool($value),
            'float' => is_float($value),
            'integer' => is_int($value),
            'string' => is_string($value),
            'mixed' => true,
        };
    }

    private function validationError(mixed $value): ?string
    {
        if ($this->rules === []) {
            return null;
        }

        $validator = Validator::make(
            ['value' => $value],
            ['value' => $this->rules],
            attributes: ['value' => $this->label ?? $this->key],
        );

        return $validator->fails()
            ? $validator->errors()->first('value')
            : null;
    }
}
