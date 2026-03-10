<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Describes a configurable setting for a Digital Worker tool.
 *
 * Used by the Tool Workspace UI to render setup forms
 * and by the SettingsService to store/retrieve values.
 */
final readonly class ToolConfigField
{
    /**
     * @param  string  $key  Settings key (e.g., 'ai.tools.web_search.provider')
     * @param  string  $label  Human-readable label for the form
     * @param  string  $type  Field type: 'text', 'secret', 'select', 'boolean'
     * @param  bool  $encrypted  Whether to store encrypted in Settings
     * @param  string|null  $help  Optional help text shown below the field
     * @param  array<string, string>  $options  For 'select' type: value => label pairs
     * @param  string|null  $showWhen  Conditional display: 'key=value' (e.g., 'ai.tools.web_search.provider=parallel')
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $encrypted = false,
        public ?string $help = null,
        public array $options = [],
        public ?string $showWhen = null,
    ) {}
}
