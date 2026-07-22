<?php

use App\Base\Database\Livewire\DataShare\Settings;

/** @var Settings $this */
/** @var array<string, mixed> $group */

$providerField = collect($group['fields'])->firstWhere('key', 'data_share.mirror.provider');
$urlField = collect($group['fields'])->firstWhere('key', 'data_share.mirror.url');
$providerFormKey = 'data_share__mirror__provider';
$urlFormKey = 'data_share__mirror__url';
$provider = (string) ($values[$providerFormKey] ?? 'supabase');
$hasSavedConnection = $this->hasSavedMirrorConnection;
$savedProject = $this->savedSupabaseProject;
?>

<div class="space-y-6">
    <div class="max-w-xl">
        <x-ui.select
            id="setting-data-share-mirror-provider"
            wire:model.live="values.{{ $providerFormKey }}"
            :label="__($providerField['label'])"
            :help="__($providerField['help'])"
            :error="$errors->first('values.'.$providerFormKey)"
        >
            @foreach (($providerField['options'] ?? []) as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}">{{ __($optionLabel) }}</option>
            @endforeach
        </x-ui.select>
    </div>

    @if ($provider === 'supabase')
        @if ($hasSavedConnection && ! $replaceSavedSupabaseConnection)
            <section class="space-y-4 border-t border-border-default pt-5" aria-labelledby="supabase-connected-heading">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex min-w-0 gap-3">
                        <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-status-success-subtle text-status-success">
                            <x-icon name="heroicon-o-check" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0">
                            <h3 id="supabase-connected-heading" class="text-sm font-medium text-ink">
                                {{ __('Supabase connection saved') }}
                            </h3>
                            @if ($savedProject)
                                <p class="mt-1 text-sm text-muted">
                                    {{ __(':project is the development mirror project.', ['project' => $savedProject['name']]) }}
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <x-ui.badge>{{ $savedProject['ref'] }}</x-ui.badge>
                                    @if ($savedProject['region'] !== '')
                                        <x-ui.badge>{{ $savedProject['region'] }}</x-ui.badge>
                                    @endif
                                    <x-ui.link
                                        kind="external"
                                        href="https://supabase.com/dashboard/project/{{ rawurlencode($savedProject['ref']) }}"
                                        class="text-xs"
                                    >
                                        {{ __('Open in Supabase') }}
                                    </x-ui.link>
                                </div>
                            @else
                                <p class="mt-1 text-sm text-muted">
                                    {{ __('The database credential is encrypted and remains write-only.') }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button
                        type="button"
                        variant="control"
                        size="sm"
                        wire:click="finishSupabaseMirrorSetup"
                        wire:loading.attr="disabled"
                        wire:target="finishSupabaseMirrorSetup"
                    >
                        <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="finishSupabaseMirrorSetup">{{ __('Check and prepare mirror') }}</span>
                        <span wire:loading wire:target="finishSupabaseMirrorSetup">{{ __('Checking…') }}</span>
                    </x-ui.button>
                    <x-ui.link :href="route('admin.system.data-share.index').'#mirror'">
                        {{ __('Open Mirror') }}
                    </x-ui.link>
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        wire:click="beginSupabasePasswordUpdate"
                        :disabled="$updatingSupabaseDatabasePassword"
                    >
                        {{ __('Update database password') }}
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        variant="danger-ghost"
                        size="sm"
                        wire:click="removeMirrorConnection"
                        wire:confirm="{{ __('Remove the saved development mirror connection? The Supabase project itself will not be deleted.') }}"
                    >
                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                        {{ __('Remove connection') }}
                    </x-ui.button>
                </div>

                @if ($errors->first('values.'.$urlFormKey))
                    <p class="text-sm text-status-danger">{{ $errors->first('values.'.$urlFormKey) }}</p>
                @endif

                @if ($updatingSupabaseDatabasePassword)
                    <div class="max-w-xl space-y-3 border-t border-border-default pt-4">
                        <p class="text-sm text-muted">
                            {{ __('Enter the database password for :project. Belimbing verifies it against Supabase, then replaces the saved encrypted credential — the project stays the same, so there is nothing else to choose.', ['project' => $savedProject['name'] ?? __('this project')]) }}
                        </p>
                        <x-ui.secret-input
                            id="supabase-update-database-password"
                            wire:model="supabaseDatabasePassword"
                            :label="__('Database password')"
                            :placeholder="__('Enter the Supabase project database password')"
                            :help="__('This is the password created with the Supabase project, not a personal access token or project API key.')"
                            :error="$errors->first('supabaseDatabasePassword')"
                            :show-reveal-button="true"
                            autocomplete="new-password"
                        />
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.button
                                type="button"
                                variant="primary"
                                size="sm"
                                wire:click="updateSupabaseDatabasePassword"
                                wire:loading.attr="disabled"
                                wire:target="updateSupabaseDatabasePassword"
                            >
                                <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                                <span wire:loading.remove wire:target="updateSupabaseDatabasePassword">{{ __('Save new password') }}</span>
                                <span wire:loading wire:target="updateSupabaseDatabasePassword">{{ __('Saving…') }}</span>
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelSupabasePasswordUpdate">
                                {{ __('Cancel') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </section>
        @else
            <section class="space-y-5 border-t border-border-default pt-5" aria-labelledby="supabase-setup-heading">
                <div>
                    <h3 id="supabase-setup-heading" class="text-base font-medium tracking-tight text-ink">
                        {{ $replaceSavedSupabaseConnection ? __('Change Supabase connection') : __('Set up Supabase') }}
                    </h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-muted">
                        {{ __('A development mirror is a shared PostgreSQL database for moving selected tables between Belimbing development installations—for example, to continue with the same development data on another machine. Nothing moves automatically: you review the tables and direction in Mirror before every transfer. Setup does not change or wipe your local database.') }}
                    </p>
                    @if ($replaceSavedSupabaseConnection)
                        <div class="mt-2">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelSupabaseReplacement">
                                {{ __('Keep current connection') }}
                            </x-ui.button>
                        </div>
                    @endif
                </div>

                @if (! $supabaseDiscoveryComplete)
                    <div class="max-w-2xl space-y-4">
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium text-ink">{{ __('Choose your situation') }}</legend>
                            <div class="grid gap-3 md:grid-cols-2">
                                <x-ui.radio
                                    id="supabase-connection-path-setup"
                                    name="supabase-connection-path"
                                    value="setup"
                                    wire:model.live="supabaseConnectionPath"
                                    :label="__('Set up a mirror')"
                                    :help="__('Create a dedicated Supabase project or prepare a project you already own.')"
                                />
                                <x-ui.radio
                                    id="supabase-connection-path-existing"
                                    name="supabase-connection-path"
                                    value="existing"
                                    wire:model.live="supabaseConnectionPath"
                                    :label="__('Connect to an existing mirror')"
                                    :help="__('Use a mirror that was already prepared from another Belimbing installation.')"
                                />
                            </div>
                        </fieldset>

                        @if ($supabaseConnectionPath === 'setup')
                            @if ($this->hasSavedSupabaseAccessToken)
                                <x-ui.alert variant="info">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <p>{{ __('Supabase account access is already saved. Continue if the mirror project belongs to this account; connect a different account only when the project belongs elsewhere.') }}</p>
                                        <x-ui.button
                                            type="button"
                                            variant="control"
                                            size="sm"
                                            wire:click="continueSupabaseWithSavedToken"
                                            wire:loading.attr="disabled"
                                            wire:target="continueSupabaseWithSavedToken"
                                        >
                                            <span wire:loading.remove wire:target="continueSupabaseWithSavedToken">{{ __('Continue with this account') }}</span>
                                            <span wire:loading wire:target="continueSupabaseWithSavedToken">{{ __('Checking Supabase…') }}</span>
                                        </x-ui.button>
                                    </div>
                                </x-ui.alert>
                            @endif

                            <div class="space-y-2 text-sm">
                                <p class="font-medium text-ink">
                                    {{ $this->hasSavedSupabaseAccessToken ? __('Connect a different Supabase account') : __('Set up a mirror on Supabase') }}
                                </p>
                                <p class="leading-6 text-muted">
                                    {{ __('Belimbing first needs permission to show your Supabase organizations and projects. After that, you choose whether to create a dedicated mirror project or prepare an existing project.') }}
                                </p>
                                <ol class="list-decimal space-y-1 pl-5 leading-6 text-muted">
                                    <li>
                                        <x-ui.link kind="external" href="https://supabase.com/dashboard/account/tokens" class="text-sm">
                                            {{ __('Open Supabase Personal Access Tokens.') }}
                                        </x-ui.link>
                                        {{ __('Sign in or create a Supabase account, then generate a token to grant that permission.') }}
                                    </li>
                                    <li>{{ __('Paste the sbp_ token below, then select or create the project that will become your mirror.') }}</li>
                                </ol>
                            </div>

                            <x-ui.secret-input
                                id="supabase-management-access-token"
                                wire:model="supabaseAccessToken"
                                :label="__('Supabase personal access token')"
                                :placeholder="__('sbp_…')"
                                :help="__('Belimbing saves accepted tokens encrypted. If Supabase rejects the saved token later, Belimbing asks for a new one.')"
                                :error="$errors->first('supabaseAccessToken')"
                                :show-reveal-button="true"
                                autocomplete="new-password"
                                autocapitalize="none"
                                spellcheck="false"
                            />

                            <x-ui.button
                                type="button"
                                variant="primary"
                                wire:click="discoverSupabase"
                                wire:loading.attr="disabled"
                                wire:target="discoverSupabase"
                            >
                                <x-icon name="heroicon-o-magnifying-glass" class="h-4 w-4" />
                                <span wire:loading.remove wire:target="discoverSupabase">{{ __('Check token and find projects') }}</span>
                                <span wire:loading wire:target="discoverSupabase">{{ __('Checking Supabase…') }}</span>
                            </x-ui.button>
                        @endif
                    </div>
                @else
                    <div class="space-y-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-sm text-status-success">
                                <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                                <span>{{ __('Supabase account connected.') }}</span>
                            </div>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="returnToSupabaseConnectionChoice">
                                <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
                                {{ __('Back') }}
                            </x-ui.button>
                        </div>

                        @if ($supabaseConnectionPath === 'existing')
                            <div class="space-y-1">
                                <h4 class="text-sm font-medium text-ink">{{ __('Choose the existing mirror project') }}</h4>
                                <p class="text-sm leading-6 text-muted">
                                    {{ __('Select the Supabase project that was prepared as the Belimbing mirror, then enter that project’s database password. Belimbing obtains the connection details from Supabase; no URL is needed.') }}
                                </p>
                            </div>

                            @if ($supabaseProjects === [])
                                <x-ui.alert variant="warning">
                                    {{ __('No Supabase projects were found in this account. Go back and set up a mirror, or connect a different Supabase account.') }}
                                </x-ui.alert>
                            @else
                                <div class="max-w-xl space-y-4">
                                    <x-ui.select
                                        id="supabase-existing-project"
                                        wire:model.live="supabaseProjectRef"
                                        :label="__('Supabase project')"
                                        :error="$errors->first('supabaseProjectRef')"
                                    >
                                        @foreach ($supabaseProjects as $project)
                                            <option value="{{ $project['ref'] }}">{{ $project['name'] }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.secret-input
                                        id="supabase-existing-database-password"
                                        wire:model="supabaseDatabasePassword"
                                        value="{{ $supabaseDatabasePassword }}"
                                        :label="__('Database password')"
                                        :placeholder="__('Enter the selected project’s database password')"
                                        :help="__('This is the password created with the Supabase project, not a personal access token or project API key.')"
                                        :error="$errors->first('supabaseDatabasePassword')"
                                        :show-reveal-button="true"
                                        autocomplete="new-password"
                                    />
                                    <x-ui.button
                                        type="button"
                                        variant="primary"
                                        wire:click="useExistingSupabaseProject"
                                        wire:loading.attr="disabled"
                                        wire:target="useExistingSupabaseProject"
                                    >
                                        <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                                        <span wire:loading.remove wire:target="useExistingSupabaseProject">{{ __('Test and save connection') }}</span>
                                        <span wire:loading wire:target="useExistingSupabaseProject">{{ __('Testing…') }}</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        @else
                            <div class="space-y-1">
                                <h4 class="text-sm font-medium text-ink">{{ __('Create a Supabase project for this mirror') }}</h4>
                                <p class="text-sm leading-6 text-muted">
                                    {{ __('Skip this if a Supabase project already exists for this Belimbing mirror. Continue to create one. Supabase creates the PostgreSQL database with the project; Belimbing generates and securely saves its database password, then prepares the database as the mirror.') }}
                                </p>
                            </div>

                            @if ($supabaseOrganizations === [])
                                <x-ui.alert variant="warning">
                                    {{ __('This Supabase account cannot create projects in an organization. Go back and connect the existing mirror instead, or connect the Supabase account that should own a new project.') }}
                                </x-ui.alert>
                            @else
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.select
                                        id="supabase-organization"
                                        wire:model.live="supabaseOrganizationSlug"
                                        :label="__('Organization')"
                                        :error="$errors->first('supabaseOrganizationSlug')"
                                    >
                                        @foreach ($supabaseOrganizations as $organization)
                                            <option value="{{ $organization['slug'] }}">{{ $organization['name'] }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.select
                                        id="supabase-region-group"
                                        wire:model.live="supabaseRegionGroup"
                                        :label="__('Data region')"
                                        :help="__('Choose for latency and data-residency requirements; the closest group is preselected from this instance timezone.')"
                                        :error="$errors->first('supabaseRegionGroup')"
                                    >
                                        <option value="apac">{{ __('Asia Pacific') }}</option>
                                        <option value="emea">{{ __('Europe, Middle East, and Africa') }}</option>
                                        <option value="americas">{{ __('Americas') }}</option>
                                    </x-ui.select>
                                    <div class="md:col-span-2 max-w-xl">
                                        <x-ui.input
                                            id="supabase-project-name"
                                            wire:model.live.debounce.250ms="supabaseProjectName"
                                            :label="__('Project name')"
                                            :error="$errors->first('supabaseProjectName')"
                                            maxlength="100"
                                        />
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <x-ui.button
                                        type="button"
                                        variant="primary"
                                        wire:click="createSupabaseMirror"
                                        wire:loading.attr="disabled"
                                        wire:target="createSupabaseMirror"
                                        wire:confirm="{{ __('Create a Supabase project and initialize it with the Belimbing schema? This can affect Supabase billing. No table data is copied until you review it in Mirror.') }}"
                                    >
                                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                        <span wire:loading.remove wire:target="createSupabaseMirror">{{ __('Create project and set up mirror') }}</span>
                                        <span wire:loading wire:target="createSupabaseMirror">{{ __('Creating project…') }}</span>
                                    </x-ui.button>
                                    <p class="text-xs text-muted">{{ __('Supabase may take a few minutes to provision a new database.') }}</p>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </section>
        @endif

        @if ((! $hasSavedConnection || $replaceSavedSupabaseConnection) && ! $supabaseDiscoveryComplete && $supabaseConnectionPath === 'existing')
            <section class="space-y-4" aria-labelledby="supabase-existing-mirror-heading">
                <div>
                    <h3 id="supabase-existing-mirror-heading" class="text-base font-medium tracking-tight text-ink">
                        {{ __('Connect this machine to an existing mirror') }}
                    </h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-muted">
                        @if ($hasSavedConnection)
                            {{ __('Belimbing already knows which Supabase database to use. Enter its database password, then test and save the connection.') }}
                        @elseif ($this->hasSavedSupabaseAccessToken)
                            {{ __('Belimbing can use the saved Supabase account access to find your project. Choose the project and enter only its database password; no connection URL is needed.') }}
                        @else
                            {{ __('Copy the PostgreSQL connection URL from the existing Supabase project, then enter its database password. The URL tells this machine which project and database to connect to; it is needed only for the first connection.') }}
                        @endif
                    </p>
                </div>
                <div class="max-w-3xl space-y-3">
                    @if (! $hasSavedConnection && $this->hasSavedSupabaseAccessToken)
                        <x-ui.alert variant="info">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p>{{ __('Supabase account access is already saved. Use it to choose the mirror project; only the project’s database password is required.') }}</p>
                                <x-ui.button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    wire:click="continueSupabaseWithSavedToken"
                                    wire:loading.attr="disabled"
                                    wire:target="continueSupabaseWithSavedToken"
                                >
                                    <span wire:loading.remove wire:target="continueSupabaseWithSavedToken">{{ __('Find my project') }}</span>
                                    <span wire:loading wire:target="continueSupabaseWithSavedToken">{{ __('Finding projects…') }}</span>
                                </x-ui.button>
                            </div>
                        </x-ui.alert>
                    @else
                    @if (! $hasSavedConnection)
                        <x-ui.secret-input
                            id="setting-data-share-mirror-url"
                            wire:model="values.{{ $urlFormKey }}"
                            value="{{ (string) ($values[$urlFormKey] ?? '') }}"
                            :label="__('Supabase PostgreSQL URL')"
                            :placeholder="__('postgresql://postgres.project:[YOUR-PASSWORD]@host:6543/postgres')"
                            :help="__('In Supabase, open the project, select Connect, and copy a direct or session-pooler URL. It may contain [YOUR-PASSWORD]; the password below replaces that placeholder.')"
                            :error="$errors->first('values.'.$urlFormKey)"
                            :saved-mask="$this->savedSecretMask($urlField)"
                            autocomplete="new-password"
                            autocapitalize="none"
                            spellcheck="false"
                        />
                    @elseif ($savedProject)
                        <p class="text-sm text-muted">
                            {{ __('Project: :project', ['project' => $savedProject['name']]) }}
                        </p>
                    @endif
                    <x-ui.secret-input
                        id="supabase-manual-database-password"
                        wire:model="supabaseManualDatabasePassword"
                        value="{{ $supabaseManualDatabasePassword }}"
                        :label="__('Database password')"
                        :placeholder="__('Enter the Supabase project database password')"
                        :help="$hasSavedConnection
                            ? __('Use the database password for this project. Belimbing tests it before replacing the saved encrypted credential.')
                            : __('This is the database password created with the Supabase project, not a personal access token or project API key.')"
                        :error="$errors->first('supabaseManualDatabasePassword')"
                        :show-reveal-button="true"
                        autocomplete="new-password"
                    />
                    <x-ui.button
                        type="button"
                        variant="control"
                        size="sm"
                        wire:click="testMirrorConnection"
                        wire:loading.attr="disabled"
                        wire:target="testMirrorConnection"
                    >
                        <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="testMirrorConnection">{{ __('Test and save connection') }}</span>
                        <span wire:loading wire:target="testMirrorConnection">{{ __('Testing…') }}</span>
                    </x-ui.button>
                    @endif
                </div>
            </section>
        @endif
    @else
        <section class="max-w-3xl space-y-4 border-t border-border-default pt-5" aria-labelledby="postgres-connection-heading">
            <div>
                <h3 id="postgres-connection-heading" class="text-base font-medium tracking-tight text-ink">
                    {{ __('Connect PostgreSQL') }}
                </h3>
                <p class="mt-1 text-sm leading-6 text-muted">
                    {{ __('Paste the PostgreSQL connection URL supplied by the server operator. Belimbing tests it before saving.') }}
                </p>
            </div>

            <x-ui.secret-input
                id="setting-data-share-mirror-url"
                wire:model="values.{{ $urlFormKey }}"
                value="{{ (string) ($values[$urlFormKey] ?? '') }}"
                :label="__($urlField['label'])"
                :placeholder="$hasSavedConnection ? '' : __($urlField['placeholder'])"
                :help="__($urlField['help'])"
                :error="$errors->first('values.'.$urlFormKey)"
                :has-value="$hasSavedConnection"
                :saved-mask="$this->savedSecretMask($urlField)"
                autocomplete="new-password"
                autocapitalize="none"
                spellcheck="false"
            />

            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button
                    type="button"
                    variant="control"
                    size="sm"
                    wire:click="testMirrorConnection"
                    wire:loading.attr="disabled"
                    wire:target="testMirrorConnection"
                >
                    <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                    <span wire:loading.remove wire:target="testMirrorConnection">{{ __('Test and save connection') }}</span>
                    <span wire:loading wire:target="testMirrorConnection">{{ __('Testing…') }}</span>
                </x-ui.button>
                @if ($hasSavedConnection)
                    <x-ui.button
                        type="button"
                        variant="control"
                        size="sm"
                        wire:click="initializeMirrorProvider"
                        wire:loading.attr="disabled"
                        wire:target="initializeMirrorProvider"
                        wire:confirm="{{ __('Run the application migrations against this provider database and assign it a distinct development identity? Existing application tables are not overwritten.') }}"
                    >
                        <x-icon name="heroicon-o-circle-stack" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="initializeMirrorProvider">{{ __('Initialize schema') }}</span>
                        <span wire:loading wire:target="initializeMirrorProvider">{{ __('Initializing…') }}</span>
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        variant="danger-ghost"
                        size="sm"
                        wire:click="removeMirrorConnection"
                        wire:confirm="{{ __('Remove the saved development mirror connection? Mirror operations will remain unavailable until another URL is saved.') }}"
                    >
                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                        {{ __('Remove connection') }}
                    </x-ui.button>
                @endif
            </div>
        </section>
    @endif
</div>
