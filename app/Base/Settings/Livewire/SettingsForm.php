<?php

namespace App\Base\Settings\Livewire;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Support\Str as BlbStr;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Base Livewire form for editing settings registered in `config('settings.editable')`.
 *
 * A subclass pins one group via group(). To edit several related groups on one
 * screen, override groups() with an ordered list; the view then renders each group
 * as a tab and a single Save persists them together. Field keys are globally unique,
 * so all groups share one `values` bag without collision.
 */
abstract class SettingsForm extends Component
{
    use InteractsWithNotifications;

    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public function mount(SettingsService $settings): void
    {
        $scope = $this->companyScope();

        foreach ($this->allFields() as $field) {
            if ($this->isReadonlyField($field)) {
                continue;
            }

            $key = $field['key'];
            $formKey = $this->formKey($key);

            if ($field['encrypted'] ?? false) {
                $fieldScope = $this->scopeForField($field, $scope);

                if ($this->shouldHydrateEncryptedValue($field)) {
                    $value = $settings->get($key, '', $fieldScope);
                } elseif ($settings->has($key, $fieldScope)) {
                    $value = $this->savedSecretMask($field);
                } else {
                    $value = '';
                }

                $this->values[$formKey] = is_scalar($value) ? (string) $value : '';

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

        foreach ($this->allFields() as $field) {
            if ($this->isReadonlyField($field)) {
                continue;
            }

            $key = $field['key'];
            $formKey = $this->formKey($key);
            $value = $validated['values'][$formKey] ?? null;

            if ($field['encrypted'] ?? false) {
                $normalized = trim((string) $value);

                if (BlbStr::isUnchangedSecretValue($normalized, $this->savedSecretMask($field))) {
                    continue;
                }
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
                $this->values[$formKey] = $this->passwordFieldDisplayValue($settings, $field, $scope, $value);
            }

            $this->resetValidation('values.'.$formKey);
        }

        $this->notify(__('Settings saved.'));
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
    private function passwordFieldDisplayValue(SettingsService $settings, array $field, Scope $scope, mixed $value): string
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

    public function render(): View
    {
        $groups = array_map(
            fn (string $groupId): array => [
                'id' => $groupId,
                'config' => $this->groupConfigFor($groupId),
            ],
            $this->groups(),
        );

        return view('livewire.settings.form', [
            'groups' => $groups,
            'multiGroup' => count($groups) > 1,
            'pageTitle' => $this->pageTitle(),
            'pageSubtitle' => $this->pageSubtitle(),
        ]);
    }

    /**
     * Settings group key (e.g. 'commerce_marketplace_ebay'). Must match an entry in
     * `config('settings.editable')`. For a single-group page this is the whole form;
     * for a multi-group page it is the primary group used for default titling.
     */
    abstract protected function group(): string;

    /**
     * Ordered settings group keys rendered by this form. Defaults to the single
     * primary group; override to edit several related groups as tabs.
     *
     * @return list<string>
     */
    protected function groups(): array
    {
        return [$this->group()];
    }

    protected function pageTitle(): string
    {
        return __(':label Settings', ['label' => $this->groupConfig()['label'] ?? __('Module')]);
    }

    protected function pageSubtitle(): string
    {
        return __($this->groupConfig()['description'] ?? 'Operator-editable module settings stored in base_settings.');
    }

    /**
     * Config for the primary group. Subclasses may override to enrich fields
     * (e.g. inject dynamic option lists) before rendering.
     *
     * @return array<string, mixed>
     */
    protected function groupConfig(): array
    {
        return config('settings.editable', [])[$this->group()] ?? [];
    }

    /**
     * Config for a specific group. The primary group routes through groupConfig()
     * so subclass enrichment still applies; other groups read config directly.
     *
     * @return array<string, mixed>
     */
    protected function groupConfigFor(string $groupId): array
    {
        return $groupId === $this->group()
            ? $this->groupConfig()
            : (config('settings.editable', [])[$groupId] ?? []);
    }

    /**
     * All editable fields across every group on this form, flattened. Field keys
     * are globally unique, so hydration, validation, and save can treat them as
     * one set.
     *
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
