<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Exceptions\SupabaseMirrorSetupException;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorSetupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Str as BlbStr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

trait ManagesSupabaseMirrorSetup
{
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

    public bool $updatingSupabaseDatabasePassword = false;

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
        $this->completeSupabaseDiscovery($discovery);
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

        $this->completeSupabaseDiscovery($discovery);
    }

    /**
     * @param  array{organizations: list<array{id: string, slug: string, name: string}>, projects: list<array{ref: string, name: string, organization_slug: string, region: string, status: string, database_host: string}>}  $discovery
     */
    private function completeSupabaseDiscovery(array $discovery): void
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
        $this->supabaseSetupChoice = $this->supabaseProjectRef === '' ? 'new' : 'existing';
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

    public function changeSupabaseAccount(): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $this->supabaseConnectionPath = 'setup';
        $this->resetSupabaseDiscovery();
    }

    public function updatedSupabaseOrganizationSlug(): void
    {
        $this->storeSupabaseSetupState();
    }

    public function updatedSupabaseProjectRef(): void
    {
        $this->supabaseSetupChoice = $this->supabaseProjectRef === '' ? 'new' : 'existing';
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

    /**
     * Reveal the inline "new database password" field on the saved-connection
     * card. Because the project ref and access token are already saved, a
     * password change never needs the project-discovery round trip; it only
     * needs the new password. When either is missing we fall back to the full
     * replacement flow so the operator can paste a token and pick the project.
     */
    public function beginSupabasePasswordUpdate(): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');

        if (! $this->hasSavedSupabaseAccessToken || $this->savedSupabaseProject === null) {
            $this->startSupabaseReplacement();

            return;
        }

        $this->updatingSupabaseDatabasePassword = true;
        $this->supabaseDatabasePassword = '';
        $this->resetValidation('supabaseDatabasePassword');
        $this->dispatch('clear-secret-input', id: 'supabase-update-database-password');
    }

    public function cancelSupabasePasswordUpdate(): void
    {
        $this->updatingSupabaseDatabasePassword = false;
        $this->supabaseDatabasePassword = '';
        $this->resetValidation('supabaseDatabasePassword');
        $this->dispatch('clear-secret-input', id: 'supabase-update-database-password');
    }

    public function updateSupabaseDatabasePassword(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');

        $savedProject = $this->savedSupabaseProject;
        if ($savedProject === null) {
            $this->failProperty('supabaseDatabasePassword', __('No saved Supabase project was found. Reconnect the mirror instead.'));
        }

        $validated = $this->validate([
            'supabaseDatabasePassword' => ['required', 'string', 'max:512'],
        ]);
        $databasePassword = $validated['supabaseDatabasePassword'];
        $accessToken = $this->supabaseSetupAccessToken();

        try {
            $status = $setup->useExistingProject($accessToken, $savedProject['ref'], $databasePassword);
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

        // Success only: failProperty throws (: never), so on failure the inline
        // field stays open with the error and the typed password preserved.
        $this->updatingSupabaseDatabasePassword = false;
        $this->supabaseDatabasePassword = '';
        $this->dispatch('clear-secret-input', id: 'supabase-update-database-password');
        $this->completeSupabaseSetupAction($status);
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

    public function checkSupabaseMirrorConnection(SupabaseMirrorSetupService $setup): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');

        try {
            $status = $setup->check();
        } catch (DataShareMirrorException $exception) {
            $this->fail($this->formKey('data_share.mirror.url'), $exception->getMessage());
        } catch (Throwable $exception) {
            $this->fail(
                $this->formKey('data_share.mirror.url'),
                DataShareMirrorException::unexpected('supabase_check', $exception)->getMessage(),
            );
        }

        $this->notify($status->message, $status->available ? 'success' : 'warning');
    }

    /** @return array{ref: string, name: string, organization: string, region: string}|null */
    public function getSavedSupabaseProjectProperty(): ?array
    {
        $settings = app(SettingsService::class);
        $ref = trim((string) $settings->get(SupabaseMirrorSetupService::PROJECT_REF_SETTING));

        if ($ref === '') {
            return null;
        }

        return [
            'ref' => $ref,
            'name' => trim((string) $settings->get(SupabaseMirrorSetupService::PROJECT_NAME_SETTING)),
            'organization' => trim((string) $settings->get(SupabaseMirrorSetupService::ORGANIZATION_SETTING)),
            'region' => trim((string) $settings->get(SupabaseMirrorSetupService::REGION_SETTING)),
        ];
    }

    public function getHasSavedSupabaseAccessTokenProperty(): bool
    {
        return app(SupabaseMirrorSetupService::class)->savedAccessToken() !== null;
    }

    public function getSupabaseMirrorNeedsInitializationProperty(): bool
    {
        return app(SupabaseMirrorSetupService::class)->needsInitialization();
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
                    : __('Supabase database connected. Continue in Mirror to choose tables.'),
                'success',
            );

            return;
        }

        $this->notify(
            $created
                ? __('The Supabase project was created and its encrypted connection was saved. Supabase may still be provisioning it; use Initialize mirror when it is ready.')
                : __('The database connection was saved. Initialize the mirror before transferring data.'),
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
                $message.' '.__('The encrypted project connection was kept; use Initialize mirror after resolving the problem.'),
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

        $this->supabaseOrganizationSlug = in_array($organizationSlug, $organizationSlugs, true)
            ? $organizationSlug
            : ($organizationSlugs[0] ?? '');
        if ($choice === 'new') {
            $this->supabaseProjectRef = '';
        } elseif (in_array($projectRef, $projectRefs, true)) {
            $this->supabaseProjectRef = $projectRef;
        } else {
            $this->supabaseProjectRef = $projectRefs[0] ?? '';
        }
        $this->supabaseSetupChoice = $this->supabaseProjectRef === '' ? 'new' : 'existing';

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
}
