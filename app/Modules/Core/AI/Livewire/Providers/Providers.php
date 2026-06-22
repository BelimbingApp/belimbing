<?php

//
// Unified full-page component for AI provider management.
//
// Combines the former Connections (provider CRUD, model management) and
// Catalog (provider discovery/browsing) into a single page. Connected
// providers appear at the top; the full catalog sits below as a
// secondary discovery section.

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Services\ModelCatalogService;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Media\PhotoCleanup\PhotoCleanupConnectionTester;
use App\Base\Media\PhotoCleanup\PhotoCleanupSelection;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Livewire\Concerns\FormatsDisplayValues;
use App\Modules\Core\AI\Livewire\Concerns\ManagesModels;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviderHelp;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviders;
use App\Modules\Core\AI\Livewire\Concerns\ManagesSync;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\AiProviderFamilyRegistry;
use App\Modules\Core\AI\Services\ProviderDefinitionRegistry;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class Providers extends Component implements ProvidesLaraPageContext
{
    use FormatsDisplayValues;
    use InteractsWithNotifications;
    use ManagesModels;
    use ManagesProviderHelp;
    use ManagesProviders;
    use ManagesSync;

    /** Which connected provider row is expanded to show models. */
    public ?int $expandedProviderId = null;

    /**
     * Provider-owned advanced settings schema for the edit modal.
     *
     * @var list<array{
     *   state_key: string,
     *   settings_key: string,
     *   label: string,
     *   help: string|null,
     *   input_type: string,
     *   default: mixed,
     *   rules: list<string>
     * }>
     */
    public array $advancedSettingsSchema = [];

    /** @var array<string, mixed> Livewire state keyed by schema.state_key */
    public array $advancedSettings = [];

    /** @var array<string, bool> Whether schema.state_key is overridden in base_settings */
    public array $advancedSettingsOverridden = [];

    /**
     * Toggle expansion of a connected provider row.
     */
    public function toggleProvider(int $providerId): void
    {
        if (! AiProvider::query()->llm()->whereKey($providerId)->exists()) {
            return;
        }

        $this->expandedProviderId = $this->expandedProviderId === $providerId ? null : $providerId;
    }

    /**
     * Re-render after the image-setup modal saves so the Image tab's catalog
     * reflects the provider's new configured/connected state.
     */
    #[On('image-providers-updated')]
    public function refreshImageProviders(): void
    {
        // No state to change — the listener triggers a re-render, which
        // recomputes $imageProviders from the registry.
    }

    /**
     * Run the per-provider connectivity handshake for a Vision provider and
     * surface the honest result through the standard notification hub. This is
     * a one-shot row action: the result goes to `<x-ui.notification-hub>` (a
     * transient toast on success, a sticky alert on error) rather than an
     * inline row mutation, so the Vision table layout stays stable across the
     * call. Only providers whose registered adapter implements
     * TestsConnection (PhotoRoom today) get a real handshake; others get an
     * honest "no handshake available" result without touching the engine. See
     * docs/plans/media-photo-cleanup-providers.md.
     */
    public function testImageConnection(string $providerKey): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $result = app(PhotoCleanupConnectionTester::class)->test($companyId, $providerKey);

        $message = $result->detail !== null
            ? $result->label.' · '.$result->detail
            : $result->label;

        $this->notify($message, $result->ok ? 'success' : 'error');
    }

    /**
     * Choose the active photo-cleanup provider for the company. The choice is
     * persisted to a company-scoped setting and the row re-renders so the
     * `Active` badge moves honestly — only a `Ready` provider (adapter bound
     * + key stored) is selectable. See docs/plans/media-photo-cleanup-providers.md.
     */
    public function setActiveImageProvider(string $providerKey): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        // Only a Ready provider (adapter bound + key stored) is selectable.
        $ready = collect(app(AiProviderFamilyRegistry::class)->family('image')?->providers($companyId) ?? [])
            ->first(fn ($summary) => $summary->providerKey === $providerKey && $summary->connected);

        if ($ready === null) {
            $this->notify(__('That provider is not ready. Add a key first.'), 'error');

            return;
        }

        app(PhotoCleanupSelection::class)->setActiveProvider($companyId, $providerKey);

        $this->notify(__('Photo cleanup now uses this provider.'));
    }

    protected function afterOpenEditProvider(AiProvider $provider): void
    {
        $this->advancedSettingsSchema = [];
        $this->advancedSettings = [];
        $this->advancedSettingsOverridden = [];

        $definition = app(ProviderDefinitionRegistry::class)->for($provider->name);
        $settings = app(SettingsService::class);

        foreach ($definition->advancedSettings() as $setting) {
            $schema = $setting->toArray();
            $stateKey = $schema['state_key'];
            $settingsKey = $schema['settings_key'];
            $default = $schema['default'];

            $overridden = $settings->has($settingsKey, scope: null);
            $value = $settings->get($settingsKey, default: null, scope: null);

            $current = $overridden && $value !== null && $value !== ''
                ? $value
                : $default;

            $this->advancedSettingsSchema[] = $schema;
            $this->advancedSettings[$stateKey] = $current;
            $this->advancedSettingsOverridden[$stateKey] = $overridden;
        }
    }

    public function saveAdvancedSettings(): void
    {
        if (! $this->isEditingProvider || $this->advancedSettingsSchema === []) {
            return;
        }

        $rules = [];

        foreach ($this->advancedSettingsSchema as $schema) {
            $stateKey = $schema['state_key'] ?? null;
            $fieldRules = $schema['rules'] ?? [];

            if (! is_string($stateKey) || $stateKey === '' || ! is_array($fieldRules) || $fieldRules === []) {
                continue;
            }

            /** @var list<string> $fieldRules */
            $rules['advancedSettings.'.$stateKey] = $fieldRules;
        }

        if ($rules !== []) {
            $this->validate($rules);
        }

        $settings = app(SettingsService::class);

        foreach ($this->advancedSettingsSchema as $schema) {
            $stateKey = $schema['state_key'] ?? null;
            $settingsKey = $schema['settings_key'] ?? null;

            if (! is_string($stateKey) || $stateKey === '' || ! is_string($settingsKey) || $settingsKey === '') {
                continue;
            }

            $settings->set($settingsKey, $this->advancedSettings[$stateKey] ?? null, scope: null);
            $this->advancedSettingsOverridden[$stateKey] = true;
        }

        $this->notify(__('Advanced settings saved.'));
    }

    public function resetAdvancedSettings(): void
    {
        if (! $this->isEditingProvider || $this->advancedSettingsSchema === []) {
            return;
        }

        $settings = app(SettingsService::class);

        foreach ($this->advancedSettingsSchema as $schema) {
            $stateKey = $schema['state_key'] ?? null;
            $settingsKey = $schema['settings_key'] ?? null;
            $default = $schema['default'] ?? null;

            if (! is_string($stateKey) || $stateKey === '' || ! is_string($settingsKey) || $settingsKey === '') {
                continue;
            }

            $settings->forget($settingsKey, scope: null);
            $this->advancedSettingsOverridden[$stateKey] = false;
            $this->advancedSettings[$stateKey] = $default;
        }

        $this->notify(__('Advanced settings reset.'));
    }

    public function pageContext(): PageContext
    {
        $companyId = $this->getCompanyId();
        $connectedCount = $companyId !== null
            ? AiProvider::query()->forCompany($companyId)->llm()->count()
            : 0;

        return new PageContext(
            route: 'admin.ai.providers',
            url: route('admin.ai.providers'),
            title: 'AI Providers ('.$connectedCount.' connected)',
            module: 'AI',
            resourceType: 'provider',
            visibleActions: ['Connect provider', 'Sync models', 'Browse catalog'],
        );
    }

    public function render(): View
    {
        $companyId = $this->getCompanyId();

        // ── Connected providers (top section) ──
        $providers = collect();
        $expandedModels = collect();

        if ($companyId !== null) {
            $providers = AiProvider::query()
                ->forCompany($companyId)
                ->llm()
                ->withCount('models')
                ->orderBy('priority')
                ->orderBy('display_name')
                ->get();

            if ($this->expandedProviderId !== null) {
                $expandedModels = AiProviderModel::query()
                    ->where('ai_provider_id', $this->expandedProviderId)
                    ->whereHas('provider', fn ($query) => $query->llm())
                    ->orderBy('model_id')
                    ->get();
            }
        }

        // Templates for the manual-add modal. The full provider catalog (the
        // "Add a Provider" discovery section) is rendered by the lazy
        // <livewire:admin.ai.providers.catalog-browser> island instead.
        $templates = collect(app(ModelCatalogService::class)->getProviders())
            ->map(fn ($t, $key) => ['value' => $key, 'label' => $t['display_name'] ?? $key])
            ->values()
            ->all();

        // Image-processing family providers (connected + available to connect),
        // pulled from the registry so the Image tab stays in sync as providers
        // are added. The LLM tab is driven by $providers + the catalog island.
        // See docs/plans/ai-provider-families.md.
        $imageProviders = app(AiProviderFamilyRegistry::class)->family('image')?->providers($companyId) ?? [];

        return view('livewire.admin.ai.providers.providers', [
            'providers' => $providers,
            'expandedModels' => $expandedModels,
            'templateOptions' => $templates,
            'imageProviders' => $imageProviders,
            'laraActivated' => Employee::laraActivationState() === true,
        ]);
    }

    private function getCompanyId(): ?int
    {
        $user = Auth::user();

        return $user !== null && method_exists($user, 'getCompanyId')
            ? $user->getCompanyId()
            : null;
    }
}
