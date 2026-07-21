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
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class Settings extends SettingsForm
{
    use AuthorizesDataShareOperations;

    private const SUPABASE_ACCESS_TOKEN_SESSION_KEY = 'data_share.mirror.supabase.setup_token';

    public string $originalMirrorProvider = 'supabase';

    public string $supabaseAccessToken = '';

    /** @var list<array{id: string, slug: string, name: string}> */
    public array $supabaseOrganizations = [];

    /** @var list<array{ref: string, name: string, organization_slug: string, region: string, status: string}> */
    public array $supabaseProjects = [];

    public bool $supabaseDiscoveryComplete = false;

    public bool $replaceSavedSupabaseConnection = false;

    public string $supabaseSetupChoice = 'new';

    public string $supabaseOrganizationSlug = '';

    public string $supabaseProjectRef = '';

    public string $supabaseProjectName = '';

    public string $supabaseRegionGroup = 'apac';

    public string $supabaseDatabasePassword = '';

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
        session()->forget(self::SUPABASE_ACCESS_TOKEN_SESSION_KEY);
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
        $validated = $this->validate([
            'supabaseAccessToken' => ['required', 'string', 'max:2048'],
        ]);

        $accessToken = trim($validated['supabaseAccessToken']);

        try {
            $discovery = $setup->discover($accessToken);
        } catch (SupabaseMirrorSetupException $exception) {
            $this->failProperty('supabaseAccessToken', $exception->getMessage());
        } catch (Throwable) {
            $this->failProperty('supabaseAccessToken', __('Supabase setup is unavailable right now. Check the network connection and try again.'));
        }

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
        session()->put(self::SUPABASE_ACCESS_TOKEN_SESSION_KEY, Crypt::encryptString($accessToken));
        $this->supabaseAccessToken = '';
        $this->supabaseOrganizationSlug = $this->supabaseOrganizations[0]['slug'] ?? '';
        $this->supabaseProjectRef = $this->supabaseProjects[0]['ref'] ?? '';
        $this->supabaseSetupChoice = $this->supabaseOrganizations !== [] ? 'new' : 'existing';
        $this->resetValidation([
            'supabaseAccessToken',
            'supabaseOrganizationSlug',
            'supabaseProjectRef',
            'supabaseDatabasePassword',
        ]);
    }

    public function resetSupabaseDiscovery(): void
    {
        session()->forget(self::SUPABASE_ACCESS_TOKEN_SESSION_KEY);
        $this->supabaseAccessToken = '';
        $this->supabaseOrganizations = [];
        $this->supabaseProjects = [];
        $this->supabaseDiscoveryComplete = false;
        $this->supabaseOrganizationSlug = '';
        $this->supabaseProjectRef = '';
        $this->supabaseDatabasePassword = '';
        $this->resetValidation([
            'supabaseAccessToken',
            'supabaseOrganizationSlug',
            'supabaseProjectRef',
            'supabaseProjectName',
            'supabaseRegionGroup',
            'supabaseDatabasePassword',
        ]);
    }

    public function startSupabaseReplacement(): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $this->replaceSavedSupabaseConnection = true;
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

        try {
            $status = $setup->createDedicatedProject(
                $this->supabaseSetupAccessToken(),
                trim($validated['supabaseOrganizationSlug']),
                trim($validated['supabaseProjectName']),
                trim($validated['supabaseRegionGroup']),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (SupabaseMirrorSetupException|DataShareMirrorException $exception) {
            $this->failCreatedSupabaseProject($exception->getMessage());
        } catch (Throwable) {
            $this->failCreatedSupabaseProject(__('The Supabase project could not be configured. No database credential was exposed.'));
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

        try {
            $status = $setup->useExistingProject(
                $this->supabaseSetupAccessToken(),
                trim($validated['supabaseProjectRef']),
                $validated['supabaseDatabasePassword'],
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (SupabaseMirrorSetupException|DataShareMirrorException $exception) {
            $this->failProperty('supabaseDatabasePassword', $exception->getMessage());
        } catch (Throwable) {
            $this->failProperty('supabaseDatabasePassword', __('The selected Supabase project could not be configured. Check its database password and try again.'));
        }

        $this->completeSupabaseSetupAction($status);
    }

    public function finishSupabaseMirrorSetup(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');

        try {
            $status = $setup->finish();
        } catch (SupabaseMirrorSetupException|DataShareMirrorException $exception) {
            $this->fail($this->formKey('data_share.mirror.url'), $exception->getMessage());
        } catch (Throwable) {
            $this->fail($this->formKey('data_share.mirror.url'), __('Supabase setup could not finish. The saved connection was kept so you can try again.'));
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
        }
    }

    public function testMirrorConnection(DataShareMirrorManager $mirror, SettingsService $settings): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $key = $this->formKey('data_share.mirror.url');
        $value = trim((string) ($this->values[$key] ?? ''));
        $provider = $this->selectedMirrorProvider();

        try {
            if ($this->isSavedMirrorMask($value)) {
                $savedUrl = $settings->get('data_share.mirror.url');
                $status = is_string($savedUrl) ? $mirror->testConnection($savedUrl, $provider) : $mirror->status();
            } elseif ($value === '') {
                if (! $settings->has('data_share.mirror.url')) {
                    $this->fail($key, __('Enter a PostgreSQL URL before testing the mirror connection.'));
                }

                $savedUrl = $settings->get('data_share.mirror.url');
                $status = is_string($savedUrl) ? $mirror->testConnection($savedUrl, $provider) : $mirror->status();
            } else {
                $this->validateMirrorUrl($key, $value);
                $status = $mirror->testConnection($value, $provider);
            }

            $result = $status->toArray();

            if (! ($result['reachable'] ?? false)) {
                $this->fail($key, (string) ($result['message'] ?? __('The mirror connection is unavailable.')));
            }

            $this->resetValidation('values.'.$key);
            $this->notify((string) ($result['message'] ?? __('Development mirror connection verified.')), ($result['available'] ?? false) ? 'success' : 'warning');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail($key, __('The mirror connection could not be tested. Check the provider and database URL.'));
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
        } catch (Throwable) {
            $this->fail($this->formKey('data_share.mirror.url'), __('The provider schema could not be initialized. No table data was mirrored.'));
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

    private function prepareAndTestMirrorUrl(SettingsService $settings, DataShareMirrorManager $mirror): void
    {
        $key = $this->formKey('data_share.mirror.url');
        $value = trim((string) ($this->values[$key] ?? ''));
        $provider = $this->selectedMirrorProvider();

        if (($value === '' || $this->isSavedMirrorMask($value)) && $settings->has('data_share.mirror.url')) {
            $this->values[$key] = BlbStr::DEFAULT_SAVED_SECRET_MASK;

            if ($provider !== $this->originalMirrorProvider) {
                $savedUrl = $settings->get('data_share.mirror.url');
                if (! is_string($savedUrl)) {
                    $this->fail($key, __('The saved mirror credential could not be read. Replace it before changing provider.'));
                }

                try {
                    $status = $mirror->testConnection($savedUrl, $provider)->toArray();
                } catch (Throwable) {
                    $this->fail($key, __('The mirror connection could not be tested. Check the provider and database URL.'));
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
        } catch (Throwable) {
            $this->fail($key, __('The mirror connection could not be tested. Check the provider and database URL.'));
        }

        if (! ($status['reachable'] ?? false)) {
            $this->fail($key, (string) ($status['message'] ?? __('The mirror connection is unavailable.')));
        }
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
                ? __('The Supabase project was created and its encrypted connection was saved. Supabase may still be provisioning it; use Finish setup when it is ready.')
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
                $message.' '.__('The encrypted project connection was kept; use Finish setup after resolving the problem.'),
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
        $encrypted = session()->get(self::SUPABASE_ACCESS_TOKEN_SESSION_KEY);

        try {
            $accessToken = is_string($encrypted) ? trim(Crypt::decryptString($encrypted)) : '';
        } catch (DecryptException) {
            $accessToken = '';
        }

        if ($accessToken !== '') {
            return $accessToken;
        }

        $this->resetSupabaseDiscovery();
        $this->failProperty('supabaseAccessToken', __('The Supabase setup session expired. Paste a new access token to continue.'));
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
