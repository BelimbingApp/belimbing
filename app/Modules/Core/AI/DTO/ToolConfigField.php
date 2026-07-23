<?php

namespace App\Modules\Core\AI\DTO;

use App\Base\Settings\DTO\SettingDefinition;

/**
 * Describes a configurable setting for a Agent tool.
 *
 * Used by the Tool Workspace UI to render setup forms
 * and by the SettingsService to store/retrieve values.
 */
final readonly class ToolConfigField
{
    /**
     * @param  string  $type  Field type: 'text', 'secret', 'select', 'boolean'
     * @param  array<string, string>  $options  For 'select' type: value => label pairs
     * @param  string|null  $showWhen  Conditional display: 'key=value' (e.g., 'ai.tools.web_search.provider=parallel')
     */
    public function __construct(
        SettingDefinition $definition,
        public string $type = 'text',
        public array $options = [],
        public ?string $showWhen = null,
    ) {
        $this->key = $definition->key;
        $this->label = $definition->label ?? $definition->key;
        $this->encrypted = $definition->encrypted;
        $this->help = $definition->help;
    }

    public string $key;

    public string $label;

    public bool $encrypted;

    public ?string $help;
}
