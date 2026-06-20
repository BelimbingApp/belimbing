<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-ink">{{ __('Plugin manager') }}</h1>
        <p class="text-sm text-muted">{{ __('What the runtime sees, plus what is available from BelimbingApp. Read-only by design — installation runs from a shell.') }}</p>
    </header>

    <x-ui.session-flash />

    <nav class="flex gap-2 border-b border-border-default">
        <button type="button" wire:click="setTab('installed')" class="px-3 py-2 text-sm {{ $tab === 'installed' ? 'border-b-2 border-accent font-semibold text-ink' : 'text-muted hover:text-ink' }}">
            {{ __('Installed (:n)', ['n' => count($manifests)]) }}
        </button>
        <button type="button" wire:click="setTab('available')" class="px-3 py-2 text-sm {{ $tab === 'available' ? 'border-b-2 border-accent font-semibold text-ink' : 'text-muted hover:text-ink' }}">
            {{ __('Available (:n)', ['n' => count($catalogEntries)]) }}
        </button>
    </nav>

    @if ($tab === 'installed')
        @if (count($dependencyIssues) > 0)
            <div class="rounded-2xl border border-danger-border bg-danger-surface px-4 py-3 text-sm text-danger-ink">
                <div class="font-medium">{{ __('Module dependency issues') }}</div>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($dependencyIssues as $row)
                        @if ($row['issue'] === 'missing')
                            <li>{{ __(':a requires :b (:constraint), but it is not installed or enabled.', ['a' => $row['requiring'], 'b' => $row['required'], 'constraint' => $row['constraint']]) }}</li>
                        @else
                            <li>{{ __(':a requires :b (:constraint), but the installed version is :version.', ['a' => $row['requiring'], 'b' => $row['required'], 'constraint' => $row['constraint'], 'version' => $row['installed_version'] ?: __('unversioned')]) }}</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @else
            <div class="rounded-2xl border border-success-border bg-success-surface px-4 py-3 text-sm text-success-ink">
                {{ __('All required module dependencies are satisfied.') }}
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            <x-ui.card>
                <div class="text-xs uppercase tracking-wide text-muted">{{ __('Installed modules') }}</div>
                <div class="mt-1 text-2xl font-semibold text-ink">{{ count($manifests) }}</div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-xs uppercase tracking-wide text-muted">{{ __('Required deps declared') }}</div>
                <div class="mt-1 text-2xl font-semibold text-ink">{{ $requiredCount }}</div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-xs uppercase tracking-wide text-muted">{{ __('Optional deps declared') }}</div>
                <div class="mt-1 text-2xl font-semibold text-ink">{{ $optionalCount }}</div>
            </x-ui.card>
        </div>

        @foreach (['plugin' => __('Plugins'), 'source' => __('Source modules'), 'unknown' => __('Other / unrecognised role')] as $role => $heading)
            @if (count($byRole[$role]) > 0)
                <section class="space-y-2">
                    <h2 class="text-lg font-semibold text-ink">{{ $heading }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($byRole[$role] as $m)
                            <x-ui.card wire:key="manifest-{{ $m->module ?: $m->name }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="font-medium text-ink">{{ $m->module ?: $m->name }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $m->name }}</div>
                                    </div>
                                    <span class="rounded-full border border-border-default px-2 py-0.5 text-xs text-muted">{{ $m->version ?: __('unversioned') }}</span>
                                </div>
                                @if ($m->description !== '')
                                    <p class="mt-2 text-sm text-muted">{{ $m->description }}</p>
                                @endif

                                @if (count($m->requiresModules) > 0)
                                    <div class="mt-3 text-xs">
                                        <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Requires') }}</div>
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($m->requiresModules as $required => $constraint)
                                                <li class="font-mono">{{ $required }} <span class="text-muted">{{ $constraint }}</span></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if (count($m->optionalModules) > 0)
                                    <div class="mt-2 text-xs">
                                        <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Optional') }}</div>
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($m->optionalModules as $optional => $constraint)
                                                <li class="font-mono">{{ $optional }} <span class="text-muted">{{ $constraint }}</span></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if (count($m->publishesEvents) > 0)
                                    <div class="mt-2 text-xs">
                                        <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Publishes') }}</div>
                                        <ul class="mt-1 space-y-0.5 font-mono">
                                            @foreach ($m->publishesEvents as $event)
                                                <li class="break-all">{{ class_basename($event) }} <span class="text-muted">({{ $event }})</span></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if (count($m->consumesEvents) > 0)
                                    <div class="mt-2 text-xs">
                                        <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Consumes') }}</div>
                                        <ul class="mt-1 space-y-0.5 font-mono">
                                            @foreach ($m->consumesEvents as $event)
                                                <li class="break-all">{{ class_basename($event) }} <span class="text-muted">({{ $event }})</span></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <div class="mt-3 font-mono text-xs text-muted">{{ $m->path }}</div>
                            </x-ui.card>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    @endif

    @if ($tab === 'available')
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-sm text-muted">
                @if ($catalogLastFetchedAt !== null)
                    {{ __('Last fetched :ts', ['ts' => $catalogLastFetchedAt->format('Y-m-d H:i:s')]) }}
                @else
                    {{ __('Catalog has never been fetched. Click "Refresh from GitHub" to populate.') }}
                @endif
            </div>
            <x-ui.button type="button" variant="secondary" wire:click="refreshCatalog" :disabled="! $canManage">
                {{ __('Refresh from GitHub') }}
            </x-ui.button>
        </div>

        @if (count($catalogEntries) === 0)
            <x-ui.card>
                <p class="text-sm text-muted">{{ __('No catalog entries cached. Refresh from GitHub to discover BelimbingApp plugins.') }}</p>
            </x-ui.card>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($catalogEntries as $entry)
                    @php $isInstalled = in_array($entry->moduleIdentifier, $installedModuleIds, true); @endphp
                    <x-ui.card wire:key="catalog-{{ $entry->repoName }}">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-medium text-ink">{{ $entry->moduleIdentifier ?: $entry->composerName }}</div>
                                <x-ui.link kind="external" href="{{ $entry->htmlUrl }}" class="font-mono text-xs">{{ $entry->repoName }}</x-ui.link>
                            </div>
                            @if ($isInstalled)
                                <span class="rounded-full border border-success-border bg-success-surface px-2 py-0.5 text-xs text-success-ink">{{ __('Installed') }}</span>
                            @else
                                <span class="rounded-full border border-border-default px-2 py-0.5 text-xs text-muted">{{ $entry->version ?: __('unversioned') }}</span>
                            @endif
                        </div>

                        @if ($entry->description !== '')
                            <p class="mt-2 text-sm text-muted">{{ $entry->description }}</p>
                        @endif

                        <div class="mt-3 text-xs">
                            <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Role') }}</div>
                            <div class="font-mono">{{ $entry->role }}</div>
                        </div>

                        @if (! $isInstalled)
                            <div class="mt-3 text-xs">
                                <div class="font-semibold uppercase tracking-wide text-muted">{{ __('Install command') }}</div>
                                <pre class="mt-1 select-all rounded-lg border border-border-default bg-surface-subtle px-2 py-1 font-mono text-xs">{{ $entry->suggestedInstallCommand() }}</pre>
                                <p class="mt-1 text-muted">{{ __('Copy + run from a shell. Then composer install + php artisan migrate.') }}</p>
                            </div>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    @endif
</div>
