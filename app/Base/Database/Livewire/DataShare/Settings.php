<?php

namespace App\Base\Database\Livewire\DataShare;

use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Livewire\Concerns\AuthorizesDataShareOperations;
use App\Base\Database\Livewire\DataShare\Concerns\ManagesSupabaseMirrorSetup;
use App\Base\Database\Livewire\DataShare\Concerns\TestsAndPreparesMirrorConnection;
use App\Base\Database\Livewire\DataShare\Concerns\ValidatesDataShareSettings;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderInitializer;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorSetupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Livewire\SettingsForm;
use App\Base\Settings\Support\SettingsFieldValue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class Settings extends SettingsForm
{
    use AuthorizesDataShareOperations;
    use ManagesSupabaseMirrorSetup;
    use TestsAndPreparesMirrorConnection;
    use ValidatesDataShareSettings;

    public function mount(SettingsService $settings): void
    {
        parent::mount($settings);
        $instance = app(DataShareInstanceIdentityResolver::class)->current();
        $defaults = [
            'data_share.instance.id' => $instance->id,
            'data_share.instance.name' => $instance->name,
            'data_share.instance.role' => $instance->role->value,
        ];

        foreach ($defaults as $key => $value) {
            if (! $settings->has($key)) {
                $this->values[$this->formKey($key)] = $value;
            }
        }

        $this->originalMirrorProvider = $this->selectedMirrorProvider();
        $this->supabaseProjectName = Str::limit($instance->name.' development mirror', 80, '');
        $this->supabaseRegionGroup = $this->defaultSupabaseRegionGroup();
        $this->restoreSupabaseSetupState();

        if (! $this->supabaseDiscoveryComplete
            && app(SupabaseMirrorSetupService::class)->savedAccessToken() !== null) {
            $this->supabaseConnectionPath = 'existing';
        }
    }

    protected function group(): string
    {
        return 'data_share_identity';
    }

    /** @return list<string> */
    protected function groups(): array
    {
        return [
            'data_share_identity',
            'data_share_mirror',
            'data_share_transport',
            'data_share_storage',
            'data_share_transfer_limits',
            'data_share_diagnostic_limits',
        ];
    }

    /** @return array<string, mixed> */
    protected function groupConfigFor(string $groupId): array
    {
        $config = parent::groupConfigFor($groupId);

        if ($groupId !== 'data_share_mirror') {
            return $config;
        }

        $config['autosave'] = true;

        $options = app(DataShareMirrorManager::class)->providerOptions();
        foreach ($config['fields'] ?? [] as $index => $field) {
            if (($field['key'] ?? null) !== 'data_share.mirror.provider') {
                continue;
            }

            $config['fields'][$index]['options'] = $options;
            $config['fields'][$index]['rules'] = ['required', 'string', 'in:'.implode(',', array_keys($options))];
        }

        return $config;
    }

    protected function pageTitle(): string
    {
        return __('Data Share Settings');
    }

    protected function pageSubtitle(): string
    {
        return __('Instance identity, development mirror, HTTPS routes, private storage, retention, and hard transfer bounds stored in Base Settings.');
    }

    protected function pageHelp(): ?string
    {
        return __('Choose and initialize a development provider here, then use Mirror to move explicitly selected complete-table data. Local SQLite uses portable data mode without PostgreSQL client tools; transfer offers remain the immutable, separately reviewed path for promotion between environments.');
    }

    protected function pageHelpLabel(): string
    {
        return __('About Data Share settings');
    }

    public function save(SettingsService $settings, ?DataShareMirrorManager $mirror = null): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $this->prepareAndTestMirrorUrl($settings, $mirror ?? app(DataShareMirrorManager::class));
        $this->validateOfferUrls();
        $this->validatePrivateDisk();
        $this->validateDistinctPaths();
        $this->validateRelatedLimits();

        parent::save($settings);
        $this->originalMirrorProvider = $this->selectedMirrorProvider();

        if ($this->originalMirrorProvider !== 'supabase') {
            app(SupabaseMirrorSetupService::class)->forgetProjectMetadata();
            $this->resetSupabaseDiscovery();
        }
    }

    public function initializeMirrorProvider(DataShareMirrorProviderInitializer $initializer): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');

        try {
            $initializer->initialize();
            $this->notify(__('Provider schema initialized. Continue in the Mirror tab to review and copy the initial selected table data.'), 'success');
        } catch (DataShareMirrorException $exception) {
            $this->fail($this->formKey('data_share.mirror.url'), $exception->getMessage());
        } catch (Throwable $exception) {
            $this->fail(
                $this->formKey('data_share.mirror.url'),
                DataShareMirrorException::unexpected('initialize', $exception, outcomeIndeterminate: true)->getMessage(),
            );
        }
    }

    public function removeMirrorConnection(
        SettingsService $settings,
        DataShareMirrorManager $mirror,
        SupabaseMirrorSetupService $supabaseSetup,
    ): void {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $settings->forget('data_share.mirror.url');
        $supabaseSetup->forgetProjectMetadata();
        $mirror->disconnect();
        $key = $this->formKey('data_share.mirror.url');
        $this->values[$key] = '';
        $this->replaceSavedSupabaseConnection = false;
        $this->resetValidation('values.'.$key);
        $this->resetSupabaseDiscovery();
        $this->notify(__('Development mirror connection removed.'), 'success');
    }

    public function getHasSavedMirrorConnectionProperty(): bool
    {
        return app(SettingsService::class)->has('data_share.mirror.url');
    }

    private function selectedMirrorProvider(): string
    {
        return trim((string) ($this->values[$this->formKey('data_share.mirror.provider')] ?? 'supabase'));
    }

    private function formKey(string $key): string
    {
        return SettingsFieldValue::formKey($key);
    }

    private function fail(string $formKey, string $message): never
    {
        throw ValidationException::withMessages(['values.'.$formKey => $message]);
    }
}
