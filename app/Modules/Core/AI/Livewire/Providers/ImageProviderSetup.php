<?php

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\Foundation\Contracts\CompanyScoped;
use App\Base\Media\PhotoCleanup\AlibabaConfiguration;
use App\Base\Media\PhotoCleanup\BedrockConfiguration;
use App\Base\Media\PhotoCleanup\ClaidConfiguration;
use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Base\Media\PhotoCleanup\PoofConfiguration;
use App\Base\Media\PhotoCleanup\StabilityConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal setup for a Vision provider — credentials stored in company-scoped
 * {@see AiProvider} rows (family {@code image}), no model discovery.
 */
class ImageProviderSetup extends Component
{
    private const SECRET_MASK = '******';

    public bool $show = false;

    public bool $showRemoveConfirm = false;

    public string $providerKey = '';

    public string $displayName = '';

    /**
     * @var list<array{key: string, bag: string, bag_key: string, label: string, type: string, secret: bool, default?: string, help?: string, options?: array<string, string>, rules?: list<string>, placement?: string}>
     */
    public array $fields = [];

    /** @var array<string, string> Live input values keyed by field key. */
    public array $values = [];

    /** @var array<string, bool> Whether a secret field already has a stored value. */
    public array $configured = [];

    #[On('open-image-setup')]
    public function open(string $providerKey): void
    {
        $schema = $this->schemaFor($providerKey);

        if ($schema === null || $this->companyId() === null) {
            return;
        }

        $this->resetValidation();
        $this->providerKey = $providerKey;
        $this->displayName = $schema['label'];
        $this->fields = $schema['fields'];
        $this->values = [];
        $this->configured = [];

        $store = app(ImageProviderCredentialStore::class);
        $companyId = $this->companyId();

        foreach ($this->fields as $field) {
            if ($field['secret']) {
                $stored = $store->hasCredential($companyId, $this->providerKey, $field['bag_key']);
                $this->configured[$field['key']] = $stored;
                $this->values[$field['key']] = $stored ? self::SECRET_MASK : '';

                continue;
            }

            $bag = $field['bag'] === 'connection_config'
                ? $store->connectionConfig($companyId, $this->providerKey)
                : [];

            $current = $bag[$field['bag_key']] ?? null;
            $this->values[$field['key']] = is_string($current) && $current !== '' ? $current : ($field['default'] ?? '');
        }

        $this->show = true;
    }

    public function save(): void
    {
        $companyId = $this->companyId();

        if ($companyId === null) {
            return;
        }

        $rules = [];

        foreach ($this->fields as $field) {
            $rules['values.'.$field['key']] = $field['rules'] ?? ['nullable', 'string', 'max:1024'];
        }

        $this->validate($rules);

        $store = app(ImageProviderCredentialStore::class);
        $credentials = [];
        $connectionConfig = $store->connectionConfig($companyId, $this->providerKey);

        foreach ($this->fields as $field) {
            $value = trim((string) ($this->values[$field['key']] ?? ''));

            if ($field['secret']) {
                if ($value !== '' && $value !== self::SECRET_MASK) {
                    $credentials[$field['bag_key']] = $value;
                    $this->configured[$field['key']] = true;
                    $this->values[$field['key']] = self::SECRET_MASK;
                }

                continue;
            }

            $connectionConfig[$field['bag_key']] = $value;
        }

        $attributes = [
            'display_name' => $this->displayName,
            'base_url' => $this->resolvedBaseUrl(),
            'connection_config' => $connectionConfig,
        ];

        if ($credentials !== []) {
            $attributes['credentials'] = $credentials;
        }

        $store->upsert(
            $companyId,
            $this->providerKey,
            $attributes,
            Auth::user()?->employee?->id,
        );

        $this->show = false;
        $this->dispatch('image-providers-updated');
    }

    #[On('confirm-remove-image-provider')]
    public function confirmRemove(string $providerKey): void
    {
        $schema = $this->schemaFor($providerKey);

        if ($schema === null) {
            return;
        }

        $this->providerKey = $providerKey;
        $this->displayName = $schema['label'];
        $this->showRemoveConfirm = true;
    }

    public function remove(): void
    {
        $companyId = $this->companyId();

        if ($companyId === null || $this->schemaFor($this->providerKey) === null) {
            $this->showRemoveConfirm = false;

            return;
        }

        app(ImageProviderCredentialStore::class)->delete($companyId, $this->providerKey);

        $this->showRemoveConfirm = false;
        $this->reset(['providerKey', 'displayName', 'fields', 'values', 'configured']);
        $this->dispatch('image-providers-updated');
    }

    #[Computed]
    public function isConfigured(): bool
    {
        foreach ($this->configured as $stored) {
            if ($stored) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function endpointFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (array $field): bool => ($field['placement'] ?? '') === 'endpoint',
        ));
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function credentialFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (array $field): bool => ($field['placement'] ?? '') !== 'endpoint',
        ));
    }

    #[Computed]
    public function keyUrl(): ?string
    {
        return match ($this->providerKey) {
            PhotoRoomConfiguration::PROVIDER => 'https://app.photoroom.com/api-dashboard',
            AlibabaConfiguration::PROVIDER => 'https://bailian.console.alibabacloud.com/?tab=model#/api-key',
            ClaidConfiguration::PROVIDER => 'https://app.claid.ai/',
            PoofConfiguration::PROVIDER => 'https://poof.bg/',
            StabilityConfiguration::PROVIDER => 'https://platform.stability.ai/account/keys',
            BedrockConfiguration::PROVIDER => 'https://console.aws.amazon.com/bedrock/home#/api-keys/long-term/create',
            default => null,
        };
    }

    #[Computed]
    public function apiEndpoint(): string
    {
        return $this->resolvedBaseUrl();
    }

    public function render(): View
    {
        return view('livewire.admin.ai.providers.image-setup');
    }

    /** @return array{label: string, fields: list<array<string, mixed>>}|null */
    private function schemaFor(string $providerKey): ?array
    {
        return match ($providerKey) {
            PhotoRoomConfiguration::PROVIDER => [
                'label' => PhotoRoomConfiguration::PROVIDER_LABEL,
                'fields' => [
                    ['key' => 'apiKey', 'bag' => 'credentials', 'bag_key' => 'api_key', 'label' => (string) __('API key'), 'type' => 'secret', 'secret' => true, 'help' => (string) __('PhotoRoom API key from your PhotoRoom API dashboard.')],
                ],
            ],
            AlibabaConfiguration::PROVIDER => [
                'label' => AlibabaConfiguration::PROVIDER_LABEL,
                'fields' => [
                    [
                        'key' => 'region',
                        'bag' => 'connection_config',
                        'bag_key' => 'region',
                        'label' => (string) __('Endpoint region'),
                        'type' => 'select',
                        'secret' => false,
                        'placement' => 'endpoint',
                        'default' => AlibabaConfiguration::REGION_INTERNATIONAL,
                        'options' => [
                            AlibabaConfiguration::REGION_INTERNATIONAL => (string) __('International (Singapore)'),
                            AlibabaConfiguration::REGION_CHINA => (string) __('China (Beijing)'),
                        ],
                        'rules' => ['required', 'in:'.AlibabaConfiguration::REGION_INTERNATIONAL.','.AlibabaConfiguration::REGION_CHINA],
                    ],
                    ['key' => 'apiKey', 'bag' => 'credentials', 'bag_key' => 'api_key', 'label' => (string) __('DashScope API key'), 'type' => 'secret', 'secret' => true, 'help' => (string) __('Alibaba Cloud Model Studio (DashScope) key, e.g. sk-…')],
                ],
            ],
            ClaidConfiguration::PROVIDER => [
                'label' => ClaidConfiguration::PROVIDER_LABEL,
                'fields' => [
                    ['key' => 'apiKey', 'bag' => 'credentials', 'bag_key' => 'api_key', 'label' => (string) __('API key'), 'type' => 'secret', 'secret' => true, 'help' => (string) __('Claid.ai Bearer API key from your Claid dashboard.')],
                ],
            ],
            PoofConfiguration::PROVIDER => [
                'label' => PoofConfiguration::PROVIDER_LABEL,
                'fields' => [
                    ['key' => 'apiKey', 'bag' => 'credentials', 'bag_key' => 'api_key', 'label' => (string) __('API key'), 'type' => 'secret', 'secret' => true, 'help' => (string) __('Poof (poof.bg) API key.')],
                ],
            ],
            StabilityConfiguration::PROVIDER => [
                'label' => StabilityConfiguration::PROVIDER_LABEL,
                'fields' => [
                    ['key' => 'apiKey', 'bag' => 'credentials', 'bag_key' => 'api_key', 'label' => (string) __('API key'), 'type' => 'secret', 'secret' => true, 'help' => (string) __('Stability AI Platform key (starts with sk-…), sent as a Bearer token.')],
                ],
            ],
            BedrockConfiguration::PROVIDER => [
                'label' => BedrockConfiguration::PROVIDER_LABEL,
                'fields' => [
                    [
                        'key' => 'region',
                        'bag' => 'connection_config',
                        'bag_key' => 'region',
                        'label' => (string) __('AWS region'),
                        'type' => 'select',
                        'secret' => false,
                        'placement' => 'endpoint',
                        'default' => BedrockConfiguration::DEFAULT_REGION,
                        'options' => [
                            BedrockConfiguration::REGION_US_EAST_1 => (string) __('US East (N. Virginia)'),
                            BedrockConfiguration::REGION_US_EAST_2 => (string) __('US East (Ohio)'),
                            BedrockConfiguration::REGION_US_WEST_2 => (string) __('US West (Oregon)'),
                        ],
                        'rules' => ['required', 'in:'.implode(',', BedrockConfiguration::ALLOWED_REGIONS)],
                    ],
                    ['key' => 'apiKey', 'bag' => 'credentials', 'bag_key' => 'api_key', 'label' => (string) __('Bedrock API key'), 'type' => 'secret', 'secret' => true, 'help' => (string) __('Long-term Amazon Bedrock API key (AWS_BEARER_TOKEN_BEDROCK) from the Bedrock console.')],
                ],
            ],
            default => null,
        };
    }

    private function resolvedBaseUrl(): string
    {
        return match ($this->providerKey) {
            PhotoRoomConfiguration::PROVIDER => PhotoRoomConfiguration::API_BASE_URL,
            AlibabaConfiguration::PROVIDER => ($this->values['region'] ?? AlibabaConfiguration::REGION_INTERNATIONAL) === AlibabaConfiguration::REGION_CHINA
                ? AlibabaConfiguration::ENDPOINT_CHINA
                : AlibabaConfiguration::ENDPOINT_INTERNATIONAL,
            ClaidConfiguration::PROVIDER => ClaidConfiguration::API_BASE_URL,
            PoofConfiguration::PROVIDER => PoofConfiguration::API_BASE_URL,
            StabilityConfiguration::PROVIDER => StabilityConfiguration::API_BASE_URL,
            BedrockConfiguration::PROVIDER => BedrockConfiguration::endpointFor(
                is_string($this->values['region'] ?? null) && $this->values['region'] !== ''
                    ? $this->values['region']
                    : BedrockConfiguration::DEFAULT_REGION
            ),
            default => '',
        };
    }

    private function companyId(): ?int
    {
        $user = Auth::user();

        return $user instanceof CompanyScoped
            ? $user->getCompanyId()
            : null;
    }
}
