<?php

namespace App\Base\Database\Livewire\DataShare;

use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Livewire\Concerns\AuthorizesDataShareOperations;
use App\Base\Database\Livewire\DataShare\Concerns\ManagesSupabaseMirrorSetup;
use App\Base\Database\Livewire\DataShare\Concerns\ValidatesDataShareSettings;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderInitializer;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorSetupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Livewire\SettingsForm;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\Support\Str as BlbStr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class Settings extends SettingsForm
{
    use AuthorizesDataShareOperations;
    use ManagesSupabaseMirrorSetup;
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

    public function testMirrorConnection(DataShareMirrorManager $mirror, SettingsService $settings): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $key = $this->formKey('data_share.mirror.url');
        $value = trim((string) ($this->values[$key] ?? ''));
        $provider = $this->selectedMirrorProvider();
        $passwordWasEntered = $provider === 'supabase' && $this->supabaseManualDatabasePassword !== '';

        try {
            $value = $this->applySupabaseManualPassword($value, $provider, $settings, clearPassword: false);

            if ($this->isSavedMirrorMask($value)) {
                $savedUrl = $settings->get('data_share.mirror.url');
                if (! is_string($savedUrl) || trim($savedUrl) === '') {
                    $this->fail($key, __('Enter a PostgreSQL URL before testing the mirror connection.'));
                }

                $value = $savedUrl;
            } elseif ($value === '') {
                if (! $settings->has('data_share.mirror.url')) {
                    $this->fail($key, __('Enter a PostgreSQL URL before testing the mirror connection.'));
                }

                $savedUrl = $settings->get('data_share.mirror.url');
                if (! is_string($savedUrl) || trim($savedUrl) === '') {
                    $this->fail($key, __('The saved mirror credential could not be read. Replace it and try again.'));
                }

                $value = $savedUrl;
            }

            $this->validateMirrorUrl($key, $value);
            $status = $mirror->testConnection($value, $provider);
            $result = $status->toArray();

            if (! ($result['reachable'] ?? false)) {
                $errorKey = $passwordWasEntered ? 'supabaseManualDatabasePassword' : $key;
                $this->failProperty($errorKey, (string) ($result['message'] ?? __('The mirror connection is unavailable.')));
            }

            $settings->set('data_share.mirror.provider', $provider);
            $settings->set('data_share.mirror.url', $value);

            if ($provider === 'supabase') {
                if (($result['initializable'] ?? false) && ! ($result['available'] ?? false)) {
                    $settings->set(SupabaseMirrorSetupService::NEEDS_INITIALIZATION_SETTING, true);
                } else {
                    $settings->forget(SupabaseMirrorSetupService::NEEDS_INITIALIZATION_SETTING);
                }
            }

            $this->values[$key] = BlbStr::DEFAULT_SAVED_SECRET_MASK;
            $this->originalMirrorProvider = $provider;
            $this->replaceSavedSupabaseConnection = false;
            $this->resetValidation('values.'.$key);
            $this->resetValidation('supabaseManualDatabasePassword');

            if ($passwordWasEntered) {
                $this->supabaseManualDatabasePassword = '';
                $this->dispatch('clear-secret-input', id: 'supabase-manual-database-password');
            }

            if ($result['available'] ?? false) {
                $message = __('Connection successful and saved.');
            } elseif ($result['initializable'] ?? false) {
                $message = __('Connection successful and saved. Initialize the mirror before transferring data.');
            } else {
                $message = (string) ($result['message'] ?? __('Connection successful and saved, but the database is not ready to use.'));
            }

            $this->notify($message, ($result['available'] ?? false) ? 'success' : 'warning');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $exception) {
            $errorKey = $passwordWasEntered ? 'supabaseManualDatabasePassword' : $key;
            $this->failProperty($errorKey, DataShareMirrorException::unexpected('connection', $exception)->getMessage());
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

    private function prepareAndTestMirrorUrl(SettingsService $settings, DataShareMirrorManager $mirror): void
    {
        $key = $this->formKey('data_share.mirror.url');
        $value = trim((string) ($this->values[$key] ?? ''));
        $provider = $this->selectedMirrorProvider();
        $value = $this->applySupabaseManualPassword($value, $provider, $settings);
        $this->values[$key] = $value;

        if (($value === '' || $this->isSavedMirrorMask($value)) && $settings->has('data_share.mirror.url')) {
            $this->values[$key] = BlbStr::DEFAULT_SAVED_SECRET_MASK;

            if ($provider !== $this->originalMirrorProvider) {
                $savedUrl = $settings->get('data_share.mirror.url');
                if (! is_string($savedUrl)) {
                    $this->fail($key, __('The saved mirror credential could not be read. Replace it before changing provider.'));
                }

                try {
                    $status = $mirror->testConnection($savedUrl, $provider)->toArray();
                } catch (Throwable $exception) {
                    $this->fail($key, DataShareMirrorException::unexpected('connection', $exception)->getMessage());
                }
                if (! ($status['reachable'] ?? false)) {
                    $this->fail($key, (string) ($status['message'] ?? __('The mirror connection is unavailable.')));
                }
            }

            return;
        }

        if ($value === '' || $this->isSavedMirrorMask($value)) {
            return;
        }

        $this->validateMirrorUrl($key, $value);

        try {
            $status = $mirror->testConnection($value, $provider)->toArray();
        } catch (Throwable $exception) {
            $this->fail($key, DataShareMirrorException::unexpected('connection', $exception)->getMessage());
        }

        if (! ($status['reachable'] ?? false)) {
            $this->fail($key, (string) ($status['message'] ?? __('The mirror connection is unavailable.')));
        }
    }

    private function applySupabaseManualPassword(
        string $url,
        string $provider,
        SettingsService $settings,
        bool $clearPassword = true,
    ): string {
        $password = $this->supabaseManualDatabasePassword;

        if ($clearPassword) {
            $this->supabaseManualDatabasePassword = '';
            $this->dispatch('clear-secret-input', id: 'supabase-manual-database-password');
        }

        if ($provider !== 'supabase' || $password === '') {
            return $url;
        }

        if ($url === '' || $this->isSavedMirrorMask($url)) {
            $savedUrl = $settings->get('data_share.mirror.url');

            if (is_string($savedUrl)) {
                $url = $savedUrl;
            }
        }

        $updatedUrl = preg_replace_callback(
            '/\A(postgres(?:ql)?:\/\/)([^\/?#@]+)@/i',
            static function (array $matches) use ($password): string {
                $username = explode(':', $matches[2], 2)[0];

                return $matches[1].$username.':'.rawurlencode($password).'@';
            },
            $url,
            1,
        );

        return is_string($updatedUrl) ? $updatedUrl : $url;
    }

    private function selectedMirrorProvider(): string
    {
        return trim((string) ($this->values[$this->formKey('data_share.mirror.provider')] ?? 'supabase'));
    }

    private function isSavedMirrorMask(string $value): bool
    {
        return BlbStr::isUnchangedSecretValue($value, BlbStr::DEFAULT_SAVED_SECRET_MASK);
    }

    private function formKey(string $key): string
    {
        return SettingsFieldValue::formKey($key);
    }

    private function fail(string $formKey, string $message): never
    {
        throw ValidationException::withMessages(['values.'.$formKey => $message]);
    }

    private function failProperty(string $property, string $message): never
    {
        throw ValidationException::withMessages([$property => $message]);
    }
}
