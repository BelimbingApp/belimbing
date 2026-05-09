<?php
namespace App\Modules\Core\AI\Values;

/**
 * Provider-owned advanced setting (stored in base_settings).
 *
 * Livewire components should treat {@see $stateKey} as the only binding key.
 * Persistence uses {@see $settingsKey}; callers should not derive keys.
 */
final readonly class ProviderAdvancedSetting
{
    /**
     * @param  non-empty-string  $stateKey
     * @param  non-empty-string  $settingsKey
     * @param  list<string>  $rules
     */
    public function __construct(
        public string $stateKey,
        public string $settingsKey,
        public string $label,
        public ?string $help = null,
        public string $inputType = 'text',
        public mixed $default = null,
        public array $rules = [],
    ) {}

    /**
     * @return array{
     *   state_key: string,
     *   settings_key: string,
     *   label: string,
     *   help: string|null,
     *   input_type: string,
     *   default: mixed,
     *   rules: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'state_key' => $this->stateKey,
            'settings_key' => $this->settingsKey,
            'label' => $this->label,
            'help' => $this->help,
            'input_type' => $this->inputType,
            'default' => $this->default,
            'rules' => $this->rules,
        ];
    }
}
