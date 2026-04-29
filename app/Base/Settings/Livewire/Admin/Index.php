<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Settings\Livewire\Admin;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Index extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public ?string $group = null;

    public function mount(SettingsService $settings, ?string $group = null): void
    {
        $this->group = $group;
        $scope = $this->companyScope();

        foreach ($this->fields() as $field) {
            $key = $field['key'];
            $formKey = $this->formKey($key);

            $this->values[$formKey] = ($field['encrypted'] ?? false)
                ? ''
                : (string) $settings->get($key, $field['default'] ?? '', $this->scopeForField($field, $scope));
        }
    }

    public function save(SettingsService $settings): void
    {
        $validated = $this->validate($this->rules());
        $scope = $this->companyScope();

        foreach ($this->fields() as $field) {
            $key = $field['key'];
            $formKey = $this->formKey($key);
            $value = $validated['values'][$formKey] ?? null;

            if (($field['encrypted'] ?? false) && trim((string) $value) === '') {
                continue;
            }

            $value = $this->normalizeValue($value, $field);

            if ($value === null || $value === '') {
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

    public function render(): View
    {
        return view('livewire.admin.settings.index', [
            'groups' => $this->groups(),
            'pageTitle' => $this->pageTitle(),
            'pageSubtitle' => $this->pageSubtitle(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function groups(): array
    {
        $groups = config('settings.editable', []);

        if ($this->group === null || $this->group === '') {
            return $groups;
        }

        return isset($groups[$this->group])
            ? [$this->group => $groups[$this->group]]
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return collect($this->groups())
            ->flatMap(fn (array $group): array => $group['fields'] ?? [])
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            $rules['values.'.$this->formKey($field['key'])] = $field['rules'] ?? ['nullable', 'string'];
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

    private function pageTitle(): string
    {
        $groups = $this->groups();

        if (count($groups) === 1) {
            $group = reset($groups);

            return __(':label Settings', ['label' => $group['label'] ?? __('Module')]);
        }

        return __('Settings');
    }

    private function pageSubtitle(): string
    {
        $groups = $this->groups();

        if (count($groups) === 1) {
            $group = reset($groups);

            return __($group['description'] ?? 'Operator-editable module settings stored in base_settings.');
        }

        return __('Operator-editable framework settings stored in base_settings, scoped to this company where applicable.');
    }
}
