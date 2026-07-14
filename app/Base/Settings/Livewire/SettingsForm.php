<?php

namespace App\Base\Settings\Livewire;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Livewire\Concerns\HandlesSettingsFields;
use App\Base\Settings\Support\SettingsFieldValue;
use Illuminate\Contracts\View\View;
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
    use HandlesSettingsFields;
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
            $formKey = SettingsFieldValue::formKey($key);

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
                ? SettingsFieldValue::checkboxList($value, $field)
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
            $formKey = SettingsFieldValue::formKey($key);
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
            'pageHelp' => $this->pageHelp(),
            'pageHelpLabel' => $this->pageHelpLabel(),
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

    protected function pageHelp(): ?string
    {
        return null;
    }

    protected function pageHelpLabel(): string
    {
        return __('Help');
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
}
