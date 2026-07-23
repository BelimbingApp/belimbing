<?php

namespace App\Base\Settings\Services;

use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;

/**
 * Compiles one module-owned settings manifest into canonical definitions and
 * presentation-only editable fields.
 *
 * Existing settings pages declare a field once. Definition attributes are
 * extracted from that declaration, then the field is rehydrated from the
 * canonical definition for the generic renderer. This keeps source manifests
 * compact without letting UI defaults, rules, scopes, or encryption drift.
 */
final class SettingManifestCompiler
{
    private const array DEFINITION_FIELD_KEYS = [
        'default',
        'encrypted',
        'help',
        'label',
        'rules',
        'scope',
    ];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     definitions: array<string, array<string, mixed>>,
     *     editable: array<string, mixed>,
     *     runtime: list<string>,
     * }
     */
    public function compile(string $owner, array $manifest): array
    {
        $definitions = $this->explicitDefinitions($owner, $manifest);
        $editable = (array) ($manifest['editable'] ?? []);

        foreach ($editable as $groupId => &$group) {
            if (! is_string($groupId) || ! is_array($group)) {
                throw new InvalidSettingDefinitionException(
                    "Editable settings groups owned by [{$owner}] must be keyed arrays.",
                );
            }

            $fields = (array) ($group['fields'] ?? []);

            foreach ($fields as $index => $field) {
                if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                    throw new InvalidSettingDefinitionException(
                        "Editable settings group [{$groupId}] contains an invalid field.",
                    );
                }

                if (($field['type'] ?? 'text') === 'readonly') {
                    continue;
                }

                $key = $field['key'];

                if (array_key_exists($key, $definitions)) {
                    $definition = $definitions[$key];

                    foreach (self::DEFINITION_FIELD_KEYS as $definitionField) {
                        if (array_key_exists($definitionField, $field)) {
                            throw new InvalidSettingDefinitionException(
                                "Editable field [{$key}] must reference its definition instead of repeating [{$definitionField}].",
                            );
                        }
                    }

                    $definition['editable'] ??= $groupId;
                    $definition['capability'] ??= $this->optionalString($group['capability'] ?? null);
                    $definitions[$key] = $definition;
                    $fields[$index] = $this->presentationField($field, $definition);

                    continue;
                }

                $definition = $this->definitionFromField(
                    owner: $owner,
                    groupId: $groupId,
                    capability: $this->optionalString($group['capability'] ?? null),
                    field: $field,
                );
                $definitions[$key] = $definition;
                $fields[$index] = $this->presentationField($field, $definition);
            }

            $group['fields'] = $fields;
        }
        unset($group);

        return [
            'definitions' => $definitions,
            'editable' => $editable,
            'runtime' => $this->runtimeClaims($owner, $manifest),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private function explicitDefinitions(string $owner, array $manifest): array
    {
        $definitions = [];

        foreach ((array) ($manifest['definitions'] ?? []) as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new InvalidSettingDefinitionException(
                    "Settings definitions owned by [{$owner}] must be keyed arrays.",
                );
            }

            $definitions[$key] = ['owner' => $owner, ...$definition];
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function runtimeClaims(string $owner, array $manifest): array
    {
        $claims = [];

        foreach ((array) ($manifest['runtime'] ?? []) as $claim) {
            if (! is_string($claim) || trim($claim) === '') {
                throw new InvalidSettingDefinitionException(
                    "Runtime setting claims owned by [{$owner}] must be non-empty strings.",
                );
            }

            $claims[] = $claim;
        }

        return array_values(array_unique($claims));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function definitionFromField(
        string $owner,
        string $groupId,
        ?string $capability,
        array $field,
    ): array {
        $rules = array_values((array) ($field['rules'] ?? ['nullable', 'string']));
        $type = $this->valueType($field, $rules);
        $hasDefault = array_key_exists('default', $field);
        $default = $hasDefault
            ? $this->coerceDefault($field['default'], $type)
            : null;
        $nullable = in_array('nullable', $rules, true) || ! $hasDefault || $default === null;

        if ($nullable && ! in_array('nullable', $rules, true)) {
            array_unshift($rules, 'nullable');
        }

        return [
            'type' => $type,
            'scopes' => [(string) ($field['scope'] ?? 'global')],
            'default' => $default,
            'nullable' => $nullable,
            'encrypted' => (bool) ($field['encrypted'] ?? false),
            'rules' => $rules,
            'label' => $this->optionalString($field['label'] ?? null),
            'help' => $this->optionalString($field['help'] ?? null),
            'owner' => $owner,
            'editable' => $groupId,
            'capability' => $capability,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $rules
     */
    private function valueType(array $field, array $rules): string
    {
        if (($field['type'] ?? null) === 'checkbox-list' || in_array('array', $rules, true)) {
            return 'array';
        }

        if (in_array('integer', $rules, true)) {
            return 'integer';
        }

        if (in_array('numeric', $rules, true)) {
            return 'float';
        }

        if (in_array('boolean', $rules, true) || ($field['type'] ?? null) === 'checkbox') {
            return 'boolean';
        }

        return 'string';
    }

    private function coerceDefault(mixed $default, string $type): mixed
    {
        if ($default === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $default,
            'float' => (float) $default,
            'boolean' => (bool) filter_var($default, FILTER_VALIDATE_BOOLEAN),
            default => $default,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function presentationField(array $field, array $definition): array
    {
        $presentation = array_diff_key($field, array_flip(self::DEFINITION_FIELD_KEYS));

        return [
            ...$presentation,
            'default' => $definition['default'],
            'encrypted' => $definition['encrypted'],
            'help' => $definition['help'],
            'label' => $definition['label'],
            'rules' => $definition['rules'],
            'scope' => $definition['scopes'][0],
            'value_type' => $definition['type'],
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
