<?php

namespace App\Base\Settings\Livewire\Concerns;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\Support\Str as BlbStr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait HandlesSettingsFields
{
    /**
     * @param  array<string, mixed>  $field
     */
    public function fieldValue(array $field): string
    {
        if (($field['value_route'] ?? null) !== null) {
            return route($field['value_route']);
        }

        return (string) ($field['value'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public function hasEncryptedValue(array $field): bool
    {
        if (! ($field['encrypted'] ?? false)) {
            return false;
        }

        return app(SettingsService::class)->has($field['key'], $this->scopeForField($field, $this->companyScope()));
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public function savedSecretMask(array $field): string
    {
        return (string) ($field['saved_mask'] ?? $field['saved_placeholder'] ?? BlbStr::DEFAULT_SAVED_SECRET_MASK);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected function shouldHydrateEncryptedValue(array $field): bool
    {
        if (! ($field['encrypted'] ?? false)) {
            return false;
        }

        return (bool) ($field['show_reveal_button'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function passwordFieldDisplayValue(SettingsService $settings, array $field, ?Scope $scope, mixed $value): string
    {
        $fieldScope = $this->scopeForField($field, $scope);

        if (! $settings->has($field['key'], $fieldScope)) {
            return '';
        }

        if ($this->shouldHydrateEncryptedValue($field)) {
            return (string) $value;
        }

        return $this->savedSecretMask($field);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allFields(): array
    {
        $fields = [];

        foreach ($this->groups() as $groupId) {
            foreach ($this->groupConfigFor($groupId)['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->allFields() as $field) {
            if ($this->isReadonlyField($field)) {
                continue;
            }

            $rules['values.'.SettingsFieldValue::formKey($field['key'])] = $field['rules'] ?? ['nullable', 'string'];

            if (($field['type'] ?? 'text') === 'checkbox-list' && ($field['options'] ?? []) !== []) {
                $rules['values.'.SettingsFieldValue::formKey($field['key']).'.*'] = [
                    Rule::in(array_keys($field['options'])),
                ];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeValue(mixed $value, array $field): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (($field['normalize'] ?? null) === 'uppercase' && is_string($value)) {
            return strtoupper($value);
        }

        if (($field['type'] ?? 'text') === 'checkbox-list') {
            $allowed = array_keys($field['options'] ?? []);

            return SettingsFieldValue::checkboxList($value, $field, $allowed);
        }

        return match ($field['value_type'] ?? 'string') {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    private function companyScope(): Scope
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages([
                'values' => __('Your account must belong to a company before settings can be saved.'),
            ]);
        }

        return Scope::company($companyId);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function scopeForField(array $field, ?Scope $companyScope): ?Scope
    {
        if (($field['scope'] ?? 'global') !== 'company') {
            return null;
        }

        return $companyScope ?? $this->companyScope();
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function isReadonlyField(array $field): bool
    {
        return in_array(($field['type'] ?? 'text'), ['readonly'], true);
    }

    private function requiresCompanyScope(): bool
    {
        return collect($this->allFields())
            ->contains(fn (array $field): bool => ($field['scope'] ?? 'global') === 'company');
    }
}
