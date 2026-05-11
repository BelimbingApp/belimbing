<?php

namespace App\Base\Settings\Livewire;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Support\Str as BlbStr;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Base Livewire form for editing one settings group registered in
 * `config('settings.editable')`. Subclasses pin the group via group().
 */
abstract class SettingsForm extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public function mount(SettingsService $settings): void
    {
        $scope = $this->companyScope();

        foreach ($this->fields() as $field) {
            if ($this->isReadonlyField($field)) {
                continue;
            }

            $key = $field['key'];
            $formKey = $this->formKey($key);

            if ($field['encrypted'] ?? false) {
                $this->values[$formKey] = '';

                continue;
            }

            $value = $settings->get($key, $field['default'] ?? '', $this->scopeForField($field, $scope));

            $this->values[$formKey] = ($field['type'] ?? 'text') === 'checkbox-list'
                ? $this->checkboxListValues($value, $field)
                : (string) $value;
        }
    }

    public function save(SettingsService $settings): void
    {
        $validated = $this->validate($this->rules());
        $scope = $this->companyScope();

        foreach ($this->fields() as $field) {
            if ($this->isReadonlyField($field)) {
                continue;
            }

            $key = $field['key'];
            $formKey = $this->formKey($key);
            $value = $validated['values'][$formKey] ?? null;

            if (($field['encrypted'] ?? false) && trim((string) $value) === '') {
                continue;
            }

            $value = $this->normalizeValue($value, $field);

            if ($value === null || $value === '' || $value === []) {
                $settings->forget($key, $this->scopeForField($field, $scope));

                continue;
            }

            $settings->set(
                $key,
                $value,
                $this->scopeForField($field, $scope),
                (bool) ($field['encrypted'] ?? false),
            );

            if ($field['type'] === 'password') {
                $this->values[$formKey] = '';
            }

            $this->resetValidation('values.'.$formKey);
        }

        session()->flash('success', __('Settings saved.'));
    }

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
    public function encryptedValuePreview(array $field): ?string
    {
        if (! ($field['encrypted'] ?? false)) {
            return null;
        }

        $value = app(SettingsService::class)->get($field['key'], null, $this->scopeForField($field, $this->companyScope()));

        return is_string($value) && $value !== ''
            ? BlbStr::maskMiddle($value, 7, 4)
            : null;
    }

    public function render(): View
    {
        $group = $this->groupConfig();

        return view('livewire.settings.form', [
            'groupId' => $this->group(),
            'group' => $group,
            'pageTitle' => __(':label Settings', ['label' => $group['label'] ?? __('Module')]),
            'pageSubtitle' => __($group['description'] ?? 'Operator-editable module settings stored in base_settings.'),
        ]);
    }

    /**
     * Settings group key (e.g. 'marketplace_ebay'). Must match an entry in
     * `config('settings.editable')`.
     */
    abstract protected function group(): string;

    /**
     * @return array<string, mixed>
     */
    private function groupConfig(): array
    {
        $groups = config('settings.editable', []);

        return $groups[$this->group()] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return $this->groupConfig()['fields'] ?? [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            if ($this->isReadonlyField($field)) {
                continue;
            }

            $rules['values.'.$this->formKey($field['key'])] = $field['rules'] ?? ['nullable', 'string'];

            if (($field['type'] ?? 'text') === 'checkbox-list' && ($field['options'] ?? []) !== []) {
                $rules['values.'.$this->formKey($field['key']).'.*'] = [
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

            return $this->checkboxListValues($value, $field, $allowed);
        }

        return $value;
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
    private function scopeForField(array $field, Scope $companyScope): ?Scope
    {
        return ($field['scope'] ?? 'global') === 'company'
            ? $companyScope
            : null;
    }

    private function formKey(string $settingKey): string
    {
        return str_replace('.', '__', $settingKey);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    private function checkboxListValues(mixed $value, array $field, ?array $allowed = null): array
    {
        $allowed ??= array_keys($field['options'] ?? []);
        $items = is_string($value)
            ? preg_split('/[\s,]+/', trim($value))
            : (array) $value;

        return collect($items ?: [])
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '' && ($allowed === [] || in_array($item, $allowed, true)))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function isReadonlyField(array $field): bool
    {
        return in_array(($field['type'] ?? 'text'), ['readonly'], true);
    }
}
