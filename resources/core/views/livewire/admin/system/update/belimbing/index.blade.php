<div>
    <x-slot name="title">{{ __('Update Belimbing') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Belimbing')"
            :subtitle="__('Pull the latest code per Distribution Bundle and reload — the in-app deploy. Each bundle updates from its branch, then migrations run and workers reload gracefully (a brief maintenance page may show).')"
        >
            <x-slot name="help">
                <p>{{ __('A Distribution Bundle is BLB\'s installable, versioned code bundle. Each bundle lands at a known path and namespace, such as the Belimbing platform, a domain, a slot, or an extension. Today, bundles are delivered as Git repositories.') }}</p>
                <p class="mt-2">{{ __('Update pulls the selected Distribution Bundles, installs changed PHP dependencies or refreshes the autoloader, rebuilds frontend assets, runs migrations, and gracefully reloads workers. If a Git repository is private it uses the token set in GitHub Access.') }}</p>
                <p class="mt-2">{{ __('Reload FrankenPHP does not pull code, install dependencies, build assets, or run migrations. It only asks the FrankenPHP/Caddy admin API to respawn web workers, then signals Laravel queue workers to restart after their current job. Use it when deployed code is already in place but the running workers may still be serving old PHP state.') }}</p>
            </x-slot>
        </x-ui.page-header>

        @if ($checkFailures !== [])
            <x-ui.alert variant="warning">
                {{ __('Could not check latest commits for these Distribution Bundles: :bundles. Public GitHub repositories do not need a token; see the Latest column for the Git response. If one of these repositories is private, add its owner token in', ['bundles' => implode(', ', $checkFailures)]) }}
                <a href="{{ route('admin.system.update.github-access.index') }}" class="font-medium underline" wire:navigate>{{ __('GitHub Access') }}</a>.
            </x-ui.alert>
        @endif

        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-medium text-ink">{{ __('FrankenPHP reload') }}</h2>

                        @if ($lastReload !== null)
                            <x-ui.badge :variant="$lastReload['ok'] ? 'success' : 'warning'">
                                {{ $lastReload['ok'] ? __('Workers reloaded') : __('Needs attention') }}
                            </x-ui.badge>
                        @endif
                    </div>

                    @if ($lastReload !== null)
                        <p class="mt-1 text-sm text-muted">
                            {{ __('Last attempted') }}
                            <x-ui.datetime :value="$lastReload['attempted_at']" />.
                            <span>{{ $lastReload['message'] }}</span>
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            {{ __('Admin API:') }}
                            <span class="font-mono">{{ $lastReload['admin_url'] }}</span>
                        </p>
                    @else
                        <p class="mt-1 text-sm text-muted">{{ __('No FrankenPHP reload has been recorded yet.') }}</p>
                    @endif
                </div>

                <x-ui.button type="button" variant="outline" wire:click="reloadOnly" wire:loading.attr="disabled" wire:target="reloadOnly">
                    <span wire:loading.remove wire:target="reloadOnly">{{ __('Reload FrankenPHP') }}</span>
                    <span wire:loading wire:target="reloadOnly">{{ __('Reloading…') }}</span>
                </x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-medium text-ink">{{ __('Distribution Bundles') }}</h2>
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($log !== [] && ! $logPanelOpen)
                        <x-ui.button type="button" variant="outline" wire:click="showLastRun">
                            {{ __('Show last run') }}
                        </x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="primary" wire:click="updateAll" wire:loading.attr="disabled" :disabled="! $behind">
                        <span wire:loading.remove wire:target="updateAll">{{ __('Update all') }}</span>
                        <span wire:loading wire:target="updateAll">{{ __('Updating…') }}</span>
                    </x-ui.button>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('Deployment Distribution Bundles')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Distribution Bundle') }}</x-ui.th>
                        <x-ui.th>{{ __('Branch') }}</x-ui.th>
                        <x-ui.th>{{ __('Current') }}</x-ui.th>
                        <x-ui.th>{{ __('Latest') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Status') }}</x-ui.th>
                    </tr>
                </x-slot>

                @foreach ($status as $s)
                    <tr wire:key="dist-{{ $s['key'] }}">
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <div class="text-sm font-medium text-ink">{{ $s['label'] }}</div>
                            <div class="font-mono text-xs text-muted">{{ $s['repo'] ?? $s['path'] }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-sm text-muted">{{ $s['branch'] ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            @if ($s['current'])
                                <span class="font-mono text-sm text-ink">{{ $s['current']['short'] }}</span>
                                <div class="text-xs text-muted">{{ $s['current']['ago'] }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            @if ($s['latest'])
                                <span class="font-mono text-sm text-ink">{{ $s['latest']['short'] }}</span>
                                <div class="text-xs text-muted">{{ $s['latest']['ago'] }}</div>
                            @else
                                <span class="text-xs text-muted">{{ $s['error'] }}</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top text-right">
                            @if ($s['up_to_date'] === true)
                                <x-ui.badge variant="success">{{ __('Up to date') }}</x-ui.badge>
                            @elseif ($s['up_to_date'] === false)
                                <x-ui.button type="button" size="sm" variant="primary" wire:click="updateRepo('{{ $s['key'] }}')" wire:loading.attr="disabled" wire:target="updateRepo('{{ $s['key'] }}')">
                                    <span wire:loading.remove wire:target="updateRepo('{{ $s['key'] }}')">{{ __('Update') }}</span>
                                    <span wire:loading wire:target="updateRepo('{{ $s['key'] }}')">{{ __('Updating…') }}</span>
                                </x-ui.button>
                            @else
                                <x-ui.badge variant="warning">{{ __('Unknown') }}</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <div
            @class(['hidden' => ! $logPanelOpen])
            wire:loading.class.remove="hidden"
            wire:target="updateAll,updateRepo,reloadOnly"
        >
            <x-ui.card>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-medium text-ink">
                                <span wire:loading wire:target="updateAll,updateRepo,reloadOnly">{{ __('Run in progress') }}</span>
                                <span wire:loading.remove wire:target="updateAll,updateRepo,reloadOnly">{{ __('Last run') }}</span>
                            </h2>

                            @if ($runOutcome !== 'idle')
                                <x-ui.badge :variant="$runOutcomeVariant">{{ $runOutcomeLabel }}</x-ui.badge>
                            @endif
                        </div>

                        @if ($log !== [])
                            <p class="mt-1 text-xs text-muted">{{ $runSummary }}</p>
                        @endif
                    </div>

                    <button
                        type="button"
                        wire:click="closeRunLog"
                        wire:loading.attr="disabled"
                        wire:target="updateAll,updateRepo,reloadOnly"
                        class="rounded-md text-muted hover:text-ink disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent"
                        aria-label="{{ __('Close run log') }}"
                    >
                        <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="mt-2 max-h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink" aria-live="polite">
                    <div class="space-y-0" wire:stream="runLog">
                        @forelse ($log as $line)
                            <div class="{{ $this->runLineClass($line) }}">{{ $line }}</div>
                        @empty
                            <div class="text-muted">{{ __('Waiting for run output…') }}</div>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
