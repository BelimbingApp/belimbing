<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Values;

use App\Modules\Core\AI\Enums\ProviderOperation;

/**
 * Describes a single field in a provider's setup/edit form.
 *
 * Definitions return a list of these from `editorFields()`. The Blade
 * template iterates and renders each field based on its type.
 */
final readonly class ProviderField
{
    /**
     * @param  string  $key  Stable identifier used as the form field name and credentials/connection_config key
     * @param  string  $label  Human-readable label for the field
     * @param  string  $type  Field type: 'text', 'secret', or 'readonly'
     * @param  bool  $isSecret  Whether this field is stored in the encrypted credentials bag
     * @param  list<ProviderOperation>  $requiredOn  Operations where this field is required
     * @param  string|null  $placeholder  Placeholder text for the input
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $isSecret = false,
        public array $requiredOn = [],
        public ?string $placeholder = null,
    ) {}

    /**
     * Create a secret field (stored in encrypted credentials bag).
     */
    public static function secret(string $key, string $label, ?string $placeholder = null): self
    {
        return new self(
            key: $key,
            label: $label,
            type: 'secret',
            isSecret: true,
            requiredOn: [ProviderOperation::Create],
            placeholder: $placeholder,
        );
    }

    /**
     * Create a text field (stored in connection_config).
     */
    public static function text(string $key, string $label, ?string $placeholder = null): self
    {
        return new self(
            key: $key,
            label: $label,
            type: 'text',
            isSecret: false,
            requiredOn: [ProviderOperation::Create, ProviderOperation::Edit],
            placeholder: $placeholder,
        );
    }

    /**
     * Mark this field as required on the given operations (returns a new instance).
     */
    public function requiredOn(ProviderOperation ...$operations): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            type: $this->type,
            isSecret: $this->isSecret,
            requiredOn: array_values($operations),
            placeholder: $this->placeholder,
        );
    }

    /**
     * Whether this field is required for the given operation.
     */
    public function isRequiredFor(ProviderOperation $operation): bool
    {
        return in_array($operation, $this->requiredOn, true);
    }
}
