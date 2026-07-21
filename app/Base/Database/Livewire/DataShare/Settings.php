<?php

namespace App\Base\Database\Livewire\DataShare;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Exceptions\SupabaseMirrorSetupException;
use App\Base\Database\Livewire\Concerns\AuthorizesDataShareOperations;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderInitializer;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorSetupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Livewire\SettingsForm;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\Support\Str as BlbStr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class Settings extends SettingsForm
{
    use AuthorizesDataShareOperations;

    private const SUPABASE_SETUP_STATE_SESSION_KEY = 'data_share.mirror.supabase.setup_state';

    public string $originalMirrorProvider = 'supabase';

    public string $supabaseAccessToken = '';

    /** @var list<array{id: string, slug: string, name: string}> */
    public array $supabaseOrganizations = [];

    /** @var list<array{ref: string, name: string, organization_slug: string, region: string, status: string}> */
    public array $supabaseProjects = [];

    public bool $supabaseDiscoveryComplete = false;

    public bool $replaceSavedSupabaseConnection = false;

    public string $supabaseConnectionPath = 'setup';

    public string $supabaseSetupChoice = '';

    public string $supabaseOrganizationSlug = '';

    public string $supabaseProjectRef = '';

    public string $supabaseProjectName = '';

    public string $supabaseRegionGroup = 'apac';

    public string $supabaseDatabasePassword = '';

    public string $supabaseManualDatabasePassword = '';

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

    public function discoverSupabase(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $accessToken = trim($this->supabaseAccessToken);
        $this->supabaseAccessToken = '';
        $this->dispatch('clear-secret-input', id: 'supabase-management-access-token');
        $validated = validator(['supabaseAccessToken' => $accessToken], [
            'supabaseAccessToken' => ['required', 'string', 'max:2048'],
        ])->validate();
        $accessToken = trim($validated['supabaseAccessToken']);

        try {
            $discovery = $setup->discover($accessToken);
        } catch (SupabaseMirrorSetupException $exception) {
            $this->failProperty('supabaseAccessToken', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failProperty('supabaseAccessToken', DataShareMirrorException::unexpected('supabase_discovery', $exception)->getMessage());
        }

        $setup->rememberAccessToken($accessToken);
        $this->completeSupabaseDiscovery($accessToken, $discovery);
    }

    public function continueSupabaseWithSavedToken(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $accessToken = $setup->savedAccessToken();

        if ($accessToken === null) {
            $this->failProperty('supabaseAccessToken', __('No saved Supabase personal access token is available. Create a new token to continue.'));
        }

        try {
            $discovery = $setup->discover($accessToken);
        } catch (SupabaseMirrorSetupException $exception) {
            if ($exception->reasonCode === 'invalid_token') {
                $this->failExpiredSupabaseAccessToken($setup, $accessToken);
            }

            $this->failProperty('supabaseAccessToken', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failProperty(
                'supabaseAccessToken',
                DataShareMirrorException::unexpected('supabase_discovery', $exception)->getMessage().' '.__('The saved token was kept.'),
            );
        }

        $this->completeSupabaseDiscovery($accessToken, $discovery);
    }

    /**
     * @param  array{organizations: list<array{id: string, slug: string, name: string}>, projects: list<array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string}>}  $discovery
     */
    private function completeSupabaseDiscovery(string $accessToken, array $discovery): void
    {
        $this->supabaseOrganizations = $discovery['organizations'];
        $this->supabaseProjects = array_map(
            static fn (array $project): array => [
                'ref' => $project['ref'],
                'name' => $project['name'],
                'organization_slug' => $project['organization_slug'],
                'region' => $project['region'],
                'status' => $project['status'],
            ],
            $discovery['projects'],
        );
        $this->supabaseDiscoveryComplete = true;
        $this->supabaseAccessToken = '';
        $this->supabaseOrganizationSlug = $this->supabaseOrganizations[0]['slug'] ?? '';
        $this->supabaseProjectRef = $this->supabaseProjects[0]['ref'] ?? '';
        $this->supabaseSetupChoice = 'new';
        $this->resetValidation([
            'supabaseAccessToken',
            'supabaseOrganizationSlug',
            'supabaseProjectRef',
            'supabaseDatabasePassword',
        ]);
        $this->storeSupabaseSetupState();
    }

    public function resetSupabaseDiscovery(): void
    {
        session()->forget([
            self::SUPABASE_SETUP_STATE_SESSION_KEY,
        ]);
        $this->supabaseAccessToken = '';
        $this->supabaseOrganizations = [];
        $this->supabaseProjects = [];
        $this->supabaseDiscoveryComplete = false;
        $this->supabaseSetupChoice = '';
        $this->supabaseOrganizationSlug = '';
        $this->supabaseProjectRef = '';
        $this->supabaseDatabasePassword = '';
        $this->dispatch('clear-secret-input', id: 'supabase-management-access-token');
        $this->dispatch('clear-secret-input', id: 'supabase-existing-database-password');
        $this->resetValidation([
            'supabaseAccessToken',
            'supabaseOrganizationSlug',
            'supabaseProjectRef',
            'supabaseProjectName',
            'supabaseRegionGroup',
            'supabaseDatabasePassword',
        ]);
    }

    public function updatedSupabaseSetupChoice(): void
    {
        $this->storeSupabaseSetupState();
    }

    public function returnToSupabaseConnectionChoice(): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $this->supabaseConnectionPath = app(SupabaseMirrorSetupService::class)->savedAccessToken() === null
            ? 'setup'
            : 'existing';
        $this->resetSupabaseDiscovery();
    }

    public function updatedSupabaseOrganizationSlug(): void
    {
        $this->storeSupabaseSetupState();
    }

    public function updatedSupabaseProjectRef(): void
    {
        $this->storeSupabaseSetupState();
    }

    public function updatedSupabaseProjectName(): void
    {
        $this->storeSupabaseSetupState();
    }

    public function updatedSupabaseRegionGroup(): void
    {
        $this->storeSupabaseSetupState();
    }

    public function startSupabaseReplacement(): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $this->replaceSavedSupabaseConnection = true;
        $this->supabaseConnectionPath = 'existing';
        $this->resetSupabaseDiscovery();
    }

    public function cancelSupabaseReplacement(): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $this->replaceSavedSupabaseConnection = false;
        $this->resetSupabaseDiscovery();
    }

    public function createSupabaseMirror(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $organizationSlugs = array_column($this->supabaseOrganizations, 'slug');
        $validated = $this->validate([
            'supabaseOrganizationSlug' => ['required', 'string', Rule::in($organizationSlugs)],
            'supabaseProjectName' => ['required', 'string', 'max:100'],
            'supabaseRegionGroup' => ['required', Rule::in(['apac', 'emea', 'americas'])],
        ]);
        $accessToken = $this->supabaseSetupAccessToken();

        try {
            $status = $setup->createDedicatedProject(
                $accessToken,
                trim($validated['supabaseOrganizationSlug']),
                trim($validated['supabaseProjectName']),
                trim($validated['supabaseRegionGroup']),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (SupabaseMirrorSetupException $exception) {
            if ($exception->reasonCode === 'invalid_token') {
                $this->failExpiredSupabaseAccessToken($setup, $accessToken);
            }

            $this->failCreatedSupabaseProject($exception->getMessage());
        } catch (DataShareMirrorException $exception) {
            $this->failCreatedSupabaseProject($exception->getMessage());
        } catch (Throwable $exception) {
            $this->failCreatedSupabaseProject(DataShareMirrorException::unexpected('supabase_create', $exception, outcomeIndeterminate: true)->getMessage());
        }

        $this->completeSupabaseSetupAction($status, created: true);
    }

    public function useExistingSupabaseProject(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $projectRefs = array_column($this->supabaseProjects, 'ref');
        $validated = $this->validate([
            'supabaseProjectRef' => ['required', 'string', Rule::in($projectRefs)],
            'supabaseDatabasePassword' => ['required', 'string', 'max:512'],
        ]);
        $databasePassword = $validated['supabaseDatabasePassword'];
        $accessToken = $this->supabaseSetupAccessToken();

        try {
            $status = $setup->useExistingProject(
                $accessToken,
                trim($validated['supabaseProjectRef']),
                $databasePassword,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (SupabaseMirrorSetupException $exception) {
            if ($exception->reasonCode === 'invalid_token') {
                $this->failExpiredSupabaseAccessToken($setup, $accessToken);
            }

            $this->failProperty('supabaseDatabasePassword', $exception->getMessage());
        } catch (DataShareMirrorException $exception) {
            $this->failProperty('supabaseDatabasePassword', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failProperty('supabaseDatabasePassword', DataShareMirrorException::unexpected('supabase_connect', $exception)->getMessage());
        }

        $this->supabaseDatabasePassword = '';
        $this->dispatch('clear-secret-input', id: 'supabase-existing-database-password');
        $this->completeSupabaseSetupAction($status);
    }

    public function finishSupabaseMirrorSetup(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');

        try {
            $status = $setup->finish();
        } catch (SupabaseMirrorSetupException|DataShareMirrorException $exception) {
            $this->fail($this->formKey('data_share.mirror.url'), $exception->getMessage());
        } catch (Throwable $exception) {
            $this->fail(
                $this->formKey('data_share.mirror.url'),
                DataShareMirrorException::unexpected('initialize', $exception, outcomeIndeterminate: true)->getMessage().' '.__('The saved connection was kept.'),
            );
        }

        $this->notify($status->message, $status->available ? 'success' : 'warning');
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
            $settings->set('data_share.mirror.url', $value, encrypted: true);
            $this->values[$key] = BlbStr::DEFAULT_SAVED_SECRET_MASK;
            $this->originalMirrorProvider = $provider;
            $this->replaceSavedSupabaseConnection = false;
            $this->resetValidation('values.'.$key);
            $this->resetValidation('supabaseManualDatabasePassword');

            if ($passwordWasEntered) {
                $this->supabaseManualDatabasePassword = '';
                $this->dispatch('clear-secret-input', id: 'supabase-manual-database-password');
            }

            $this->notify(
                ($result['available'] ?? false)
                    ? __('Connection successful and saved.')
                    : __('Connection successful and saved. Use Check and prepare mirror when the database is ready.'),
                ($result['available'] ?? false) ? 'success' : 'warning',
            );
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

    /** @return array{ref: string, name: string, organization: string, region: string}|null */
    public function getSavedSupabaseProjectProperty(): ?array
    {
        $settings = app(SettingsService::class);
        $ref = trim((string) $settings->get(SupabaseMirrorSetupService::PROJECT_REF_SETTING, ''));

        if ($ref === '') {
            return null;
        }

        return [
            'ref' => $ref,
            'name' => trim((string) $settings->get(SupabaseMirrorSetupService::PROJECT_NAME_SETTING, $ref)),
            'organization' => trim((string) $settings->get(SupabaseMirrorSetupService::ORGANIZATION_SETTING, '')),
            'region' => trim((string) $settings->get(SupabaseMirrorSetupService::REGION_SETTING, '')),
        ];
    }

    public function getHasSavedSupabaseAccessTokenProperty(): bool
    {
        return app(SupabaseMirrorSetupService::class)->savedAccessToken() !== null;
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

    private function completeSupabaseSetupAction(DataShareMirrorConnectionStatus $status, bool $created = false): void
    {
        $this->values[$this->formKey('data_share.mirror.provider')] = 'supabase';
        $this->values[$this->formKey('data_share.mirror.url')] = BlbStr::DEFAULT_SAVED_SECRET_MASK;
        $this->originalMirrorProvider = 'supabase';
        $this->replaceSavedSupabaseConnection = false;
        $this->resetSupabaseDiscovery();

        if ($status->available) {
            $this->notify(
                $created
                    ? __('Supabase project created, connected, and initialized. Continue in Mirror to choose the initial tables.')
                    : __('Supabase connected and initialized. Continue in Mirror to choose the initial tables.'),
                'success',
            );

            return;
        }

        $this->notify(
            $created
                ? __('The Supabase project was created and its encrypted connection was saved. Supabase may still be provisioning it; use Check and prepare mirror when it is ready.')
                : $status->message,
            'warning',
        );
    }

    private function failCreatedSupabaseProject(string $message): never
    {
        if (app(SettingsService::class)->has('data_share.mirror.url')) {
            $this->values[$this->formKey('data_share.mirror.provider')] = 'supabase';
            $this->values[$this->formKey('data_share.mirror.url')] = BlbStr::DEFAULT_SAVED_SECRET_MASK;
            $this->originalMirrorProvider = 'supabase';
            $this->replaceSavedSupabaseConnection = false;
            $this->resetSupabaseDiscovery();
            $this->fail(
                $this->formKey('data_share.mirror.url'),
                $message.' '.__('The encrypted project connection was kept; use Check and prepare mirror after resolving the problem.'),
            );
        }

        $this->failProperty('supabaseProjectName', $message);
    }

    private function defaultSupabaseRegionGroup(): string
    {
        $timezone = mb_strtolower((string) config('app.timezone', 'UTC'));

        if (str_starts_with($timezone, 'asia/')
            || str_starts_with($timezone, 'australia/')
            || str_starts_with($timezone, 'pacific/')) {
            return 'apac';
        }

        if (str_starts_with($timezone, 'europe/') || str_starts_with($timezone, 'africa/')) {
            return 'emea';
        }

        return 'americas';
    }

    private function supabaseSetupAccessToken(): string
    {
        $accessToken = app(SupabaseMirrorSetupService::class)->savedAccessToken() ?? '';

        if ($accessToken !== '') {
            return $accessToken;
        }

        $this->resetSupabaseDiscovery();
        $this->failProperty('supabaseAccessToken', __('The Supabase setup session expired. Paste a new access token to continue.'));
    }

    private function failExpiredSupabaseAccessToken(SupabaseMirrorSetupService $setup, string $attemptedToken): never
    {
        if (! $setup->forgetAccessTokenIfMatches($attemptedToken)) {
            $this->resetSupabaseDiscovery();
            $this->failProperty('supabaseAccessToken', __('The saved Supabase token changed during setup. Continue with the current saved token and try again.'));
        }

        $this->resetSupabaseDiscovery();
        $this->failProperty('supabaseAccessToken', __('The saved Supabase personal access token has expired or was revoked. Create a new token to continue.'));
    }

    private function restoreSupabaseSetupState(): void
    {
        $state = session()->get(self::SUPABASE_SETUP_STATE_SESSION_KEY);
        $hasSavedAccessToken = app(SupabaseMirrorSetupService::class)->savedAccessToken() !== null;

        if (! $hasSavedAccessToken || ! is_array($state)) {
            session()->forget(self::SUPABASE_SETUP_STATE_SESSION_KEY);

            return;
        }

        $organizations = $state['organizations'] ?? null;
        $projects = $state['projects'] ?? null;

        if (! is_array($organizations) || ! is_array($projects)) {
            session()->forget(self::SUPABASE_SETUP_STATE_SESSION_KEY);

            return;
        }

        $this->supabaseOrganizations = array_values($organizations);
        $this->supabaseProjects = array_values($projects);
        $this->supabaseDiscoveryComplete = true;

        $organizationSlugs = array_column($this->supabaseOrganizations, 'slug');
        $projectRefs = array_column($this->supabaseProjects, 'ref');
        $choice = (string) ($state['choice'] ?? '');
        $organizationSlug = (string) ($state['organization_slug'] ?? '');
        $projectRef = (string) ($state['project_ref'] ?? '');
        $projectName = trim((string) ($state['project_name'] ?? ''));
        $regionGroup = (string) ($state['region_group'] ?? '');

        $this->supabaseSetupChoice = in_array($choice, ['new', 'existing'], true)
            ? $choice
            : '';
        $this->supabaseOrganizationSlug = in_array($organizationSlug, $organizationSlugs, true)
            ? $organizationSlug
            : ($organizationSlugs[0] ?? '');
        $this->supabaseProjectRef = in_array($projectRef, $projectRefs, true)
            ? $projectRef
            : ($projectRefs[0] ?? '');

        if ($projectName !== '') {
            $this->supabaseProjectName = Str::limit($projectName, 100, '');
        }

        if (in_array($regionGroup, ['apac', 'emea', 'americas'], true)) {
            $this->supabaseRegionGroup = $regionGroup;
        }
    }

    private function storeSupabaseSetupState(): void
    {
        if (! $this->supabaseDiscoveryComplete
            || app(SupabaseMirrorSetupService::class)->savedAccessToken() === null) {
            return;
        }

        session()->put(self::SUPABASE_SETUP_STATE_SESSION_KEY, [
            'organizations' => $this->supabaseOrganizations,
            'projects' => $this->supabaseProjects,
            'choice' => $this->supabaseSetupChoice,
            'organization_slug' => $this->supabaseOrganizationSlug,
            'project_ref' => $this->supabaseProjectRef,
            'project_name' => $this->supabaseProjectName,
            'region_group' => $this->supabaseRegionGroup,
        ]);
    }

    private function validateMirrorUrl(string $formKey, string $value): void
    {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (! is_array($parts)
            || ! in_array($scheme, ['postgres', 'postgresql'], true)
            || ! is_string($parts['host'] ?? null)
            || trim((string) ($parts['host'] ?? '')) === ''
            || ! is_string($parts['user'] ?? null)
            || trim((string) ($parts['user'] ?? '')) === ''
            || isset($parts['fragment'])) {
            $this->fail($formKey, __('Enter a PostgreSQL connection URL with a scheme, user, and host, without a fragment.'));
        }
    }

    private function isSavedMirrorMask(string $value): bool
    {
        return BlbStr::isUnchangedSecretValue($value, BlbStr::DEFAULT_SAVED_SECRET_MASK);
    }

    private function validateOfferUrls(): void
    {
        $key = $this->formKey('data_share.offers.base_urls');
        $raw = trim((string) ($this->values[$key] ?? ''));
        $urls = [];

        if ($raw !== '') {
            $urls = array_values(array_unique(array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', $raw) ?: [],
            ))));
        }

        if (! is_array($urls) || count($urls) > 5) {
            $this->fail($key, __('Enter at most five HTTPS base URLs.'));
        }

        foreach ($urls as $url) {
            $parts = parse_url($url);

            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || ! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! is_string($parts['host'] ?? null)
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                $this->fail($key, __('Every advertised route must be an HTTPS base URL without credentials, query, or fragment.'));
            }
        }
    }

    private function validatePrivateDisk(): void
    {
        $key = $this->formKey('data_share.disk');
        $disk = trim((string) ($this->values[$key] ?? ''));
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config) || $disk === 'public' || ($config['visibility'] ?? null) === 'public') {
            $this->fail($key, __('Choose a configured private Laravel filesystem disk.'));
        }
    }

    private function validateDistinctPaths(): void
    {
        $settingKeys = [
            'data_share.outgoing_path_prefix',
            'data_share.receiving_path_prefix',
            'data_share.incoming_path_prefix',
            'data_share.path_prefix',
        ];
        $paths = array_map(
            fn (string $key): string => trim((string) ($this->values[$this->formKey($key)] ?? ''), '/'),
            $settingKeys,
        );

        foreach ($paths as $leftIndex => $left) {
            foreach ($paths as $rightIndex => $right) {
                if ($leftIndex !== $rightIndex
                    && ($left === $right || str_starts_with($left, $right.'/'))) {
                    $this->fail(
                        $this->formKey($settingKeys[$leftIndex]),
                        __('Outgoing, Receiving, Incoming, and Diagnostic paths must be distinct and non-overlapping.'),
                    );
                }
            }
        }
    }

    private function validateRelatedLimits(): void
    {
        $scalarKey = $this->formKey('data_share.transfer_limits.max_scalar_bytes');
        $lineKey = $this->formKey('data_share.transfer_limits.max_record_line_bytes');
        $packageKey = $this->formKey('data_share.transfer_limits.max_package_bytes');
        $scalar = (int) ($this->values[$scalarKey] ?? 0);
        $line = (int) ($this->values[$lineKey] ?? 0);
        $package = (int) ($this->values[$packageKey] ?? 0);

        if ($line < $scalar) {
            $this->fail($lineKey, __('The record-line limit must be at least the scalar limit.'));
        }

        if ($package < $line) {
            $this->fail($packageKey, __('The package limit must be at least the record-line limit.'));
        }

        $diagnosticScalarKey = $this->formKey('data_share.limits.max_scalar_bytes');
        $diagnosticPackageKey = $this->formKey('data_share.limits.max_package_bytes');

        if ((int) ($this->values[$diagnosticPackageKey] ?? 0) < (int) ($this->values[$diagnosticScalarKey] ?? 0)) {
            $this->fail($diagnosticPackageKey, __('The diagnostic package limit must be at least the diagnostic scalar limit.'));
        }
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
