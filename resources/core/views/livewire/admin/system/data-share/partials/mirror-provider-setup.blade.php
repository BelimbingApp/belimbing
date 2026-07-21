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
                        variant="primary"
                        size="sm"
                        wire:click="finishSupabaseMirrorSetup"
                        wire:loading.attr="disabled"
                        wire:target="finishSupabaseMirrorSetup"
                    >
                        <x-icon name="heroicon-o-circle-stack" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="finishSupabaseMirrorSetup">{{ __('Finish setup') }}</span>
                        <span wire:loading wire:target="finishSupabaseMirrorSetup">{{ __('Checking Supabase…') }}</span>
                    </x-ui.button>
                    <x-ui.button
                        type="button"
                        variant="control"
                        size="sm"
                        wire:click="testMirrorConnection"
                        wire:loading.attr="disabled"
                        wire:target="testMirrorConnection"
                    >
                        <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="testMirrorConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testMirrorConnection">{{ __('Testing…') }}</span>
                    </x-ui.button>
                    <x-ui.link :href="route('admin.system.data-share.index').'#mirror'">
                        {{ __('Open Mirror') }}
                    </x-ui.link>
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        wire:click="startSupabaseReplacement"
                    >
                        {{ __('Change project or password') }}
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
            </section>
        @else
            <section class="space-y-5 border-t border-border-default pt-5" aria-labelledby="supabase-setup-heading">
                <div>
                    <h3 id="supabase-setup-heading" class="text-base font-medium tracking-tight text-ink">
                        {{ $replaceSavedSupabaseConnection ? __('Change Supabase connection') : __('Connect Supabase') }}
                    </h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-muted">
                        {{ __('Belimbing will find your projects, build the database connection, test it, and initialize the mirror schema. You never need to assemble a database URL.') }}
                    </p>
                    @if ($replaceSavedSupabaseConnection)
                        <div class="mt-2">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelSupabaseReplacement">
                                {{ __('Keep current connection') }}
                            </x-ui.button>
                        </div>
                    @endif
                </div>

                <ol class="grid gap-3 text-sm sm:grid-cols-3" aria-label="{{ __('Supabase setup progress') }}">
                    @foreach ([
                        ['label' => __('Connect account'), 'complete' => $supabaseDiscoveryComplete],
                        ['label' => __('Choose project'), 'complete' => false],
                        ['label' => __('Initialize mirror'), 'complete' => false],
                    ] as $index => $step)
                        <li class="flex items-center gap-2 text-ink">
                            <span @class([
                                'grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-medium',
                                'bg-status-success-subtle text-status-success' => $step['complete'],
                                'bg-surface-subtle text-muted' => ! $step['complete'],
                            ])>
                                @if ($step['complete'])
                                    <x-icon name="heroicon-o-check" class="h-3.5 w-3.5" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
                            <span>{{ $step['label'] }}</span>
                        </li>
                    @endforeach
                </ol>

                @if (! $supabaseDiscoveryComplete)
                    <div class="max-w-xl space-y-3">
                        <div class="flex items-end justify-between gap-3">
                            <p class="text-sm font-medium text-ink">{{ __('Connect your Supabase account') }}</p>
                            <x-ui.link kind="external" href="https://supabase.com/dashboard/account/tokens" class="text-sm">
                                {{ __('Get Supabase access token') }}
                            </x-ui.link>
                        </div>

                        <x-ui.secret-input
                            id="supabase-management-access-token"
                            wire:model="supabaseAccessToken"
                            :label="__('Access token')"
                            :placeholder="__('Paste the token from Supabase')"
                            :help="__('This token carries your Supabase account permissions. It leaves browser state after discovery and is kept encrypted only for this setup.')"
                            :error="$errors->first('supabaseAccessToken')"
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
                            <span wire:loading.remove wire:target="discoverSupabase">{{ __('Find my projects') }}</span>
                            <span wire:loading wire:target="discoverSupabase">{{ __('Connecting…') }}</span>
                        </x-ui.button>
                    </div>
                @else
                    <div class="space-y-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-sm text-status-success">
                                <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                                <span>{{ __('Supabase account connected for this setup.') }}</span>
                            </div>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="resetSupabaseDiscovery">
                                {{ __('Use a different token') }}
                            </x-ui.button>
                        </div>

                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium text-ink">{{ __('Which project should Belimbing use?') }}</legend>
                            <div class="grid gap-3 lg:grid-cols-2">
                                <x-ui.radio
                                    id="supabase-project-choice-new"
                                    name="supabase-project-choice"
                                    value="new"
                                    wire:model.live="supabaseSetupChoice"
                                    :label="__('Create a dedicated project')"
                                    :help="__('Recommended. Belimbing generates the database password and keeps the mirror isolated.')"
                                    :disabled="$supabaseOrganizations === []"
                                />
                                <x-ui.radio
                                    id="supabase-project-choice-existing"
                                    name="supabase-project-choice"
                                    value="existing"
                                    wire:model.live="supabaseSetupChoice"
                                    :label="__('Use an existing development project')"
                                    :help="__('You will provide its database password; Supabase cannot return that secret.')"
                                    :disabled="$supabaseProjects === []"
                                />
                            </div>
                        </fieldset>

                        @if ($supabaseSetupChoice === 'new')
                            @if ($supabaseOrganizations === [])
                                <x-ui.alert variant="warning">
                                    {{ __('This token cannot create projects in an organization. Use an existing project or change the token.') }}
                                </x-ui.alert>
                            @else
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.select
                                        id="supabase-organization"
                                        wire:model="supabaseOrganizationSlug"
                                        :label="__('Organization')"
                                        :error="$errors->first('supabaseOrganizationSlug')"
                                    >
                                        @foreach ($supabaseOrganizations as $organization)
                                            <option value="{{ $organization['slug'] }}">{{ $organization['name'] }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.select
                                        id="supabase-region-group"
                                        wire:model="supabaseRegionGroup"
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
                                            wire:model="supabaseProjectName"
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
                        @else
                            @if ($supabaseProjects === [])
                                <x-ui.alert variant="warning">
                                    {{ __('No existing projects are available to this token. Create a dedicated project or change the token.') }}
                                </x-ui.alert>
                            @else
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.select
                                        id="supabase-existing-project"
                                        wire:model="supabaseProjectRef"
                                        :label="__('Development project')"
                                        :error="$errors->first('supabaseProjectRef')"
                                    >
                                        @foreach ($supabaseProjects as $project)
                                            <option value="{{ $project['ref'] }}">
                                                {{ $project['name'] }}{{ $project['region'] !== '' ? ' · '.$project['region'] : '' }}
                                            </option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.secret-input
                                        id="supabase-existing-database-password"
                                        wire:model="supabaseDatabasePassword"
                                        :label="__('Database password')"
                                        :placeholder="__('Enter the project database password')"
                                        :help="__('This is the password set when the project was created, not the access token or an API key.')"
                                        :error="$errors->first('supabaseDatabasePassword')"
                                        autocomplete="new-password"
                                    />
                                </div>

                                <x-ui.button
                                    type="button"
                                    variant="primary"
                                    wire:click="useExistingSupabaseProject"
                                    wire:loading.attr="disabled"
                                    wire:target="useExistingSupabaseProject"
                                    wire:confirm="{{ __('Connect this development project and initialize it with the Belimbing schema? Existing non-Belimbing or non-development databases are refused.') }}"
                                >
                                    <x-icon name="heroicon-o-link" class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="useExistingSupabaseProject">{{ __('Use project and set up mirror') }}</span>
                                    <span wire:loading wire:target="useExistingSupabaseProject">{{ __('Connecting project…') }}</span>
                                </x-ui.button>
                            @endif
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <section class="border-t border-border-default pt-5">
            <x-ui.disclosure
                :title="__('Advanced connection')"
                :default-open="$errors->has('values.'.$urlFormKey)"
                panel-id="supabase-advanced-connection"
            >
                <x-slot name="hint">
                    <p class="ml-5 max-w-3xl text-sm text-muted">
                        {{ __('Use only when account discovery cannot reach a custom or IPv4-only setup. Supabase normally configures this automatically.') }}
                    </p>
                </x-slot>

                <div class="max-w-3xl space-y-3">
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
                    <x-ui.button
                        type="button"
                        variant="control"
                        size="sm"
                        wire:click="testMirrorConnection"
                        wire:loading.attr="disabled"
                        wire:target="testMirrorConnection"
                    >
                        <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="testMirrorConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testMirrorConnection">{{ __('Testing…') }}</span>
                    </x-ui.button>
                </div>
            </x-ui.disclosure>
        </section>
    @else
        <section class="max-w-3xl space-y-4 border-t border-border-default pt-5" aria-labelledby="postgres-connection-heading">
            <div>
                <h3 id="postgres-connection-heading" class="text-base font-medium tracking-tight text-ink">
                    {{ __('Connect PostgreSQL') }}
                </h3>
                <p class="mt-1 text-sm leading-6 text-muted">
                    {{ __('Enter the connection supplied by the server operator. Belimbing tests it before saving and uses portable data mode when Local is SQLite.') }}
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
                    <span wire:loading.remove wire:target="testMirrorConnection">{{ __('Test connection') }}</span>
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
