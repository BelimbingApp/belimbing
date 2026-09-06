<div>
    <x-slot name="title">{{ __('Feature Flags') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Feature Flags')"
            :subtitle="__('Declared module flags for the current tenant. Overrides apply only here; undeclared names cannot be invented.')"
        />

        <x-ui.session-flash />

        <x-ui.card>
            <div class="mb-4 max-w-md">
                <x-ui.input
                    id="feature-flags-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    :placeholder="__('Flag, module, or description')"
                />
            </div>

            <x-ui.table container="flush" :caption="__('Feature flags for the current tenant')">
                <x-slot name="head">
                    <tr>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Flag') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Module') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Default') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Effective') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Override') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-right text-xs font-medium text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                    </tr>
                </x-slot>

                @forelse ($rows as $row)
                    <tr wire:key="feature-flag-{{ $row['flag'] }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm">
                            <div class="font-medium text-ink">{{ $row['flag'] }}</div>
                            @if ($row['description'] !== '')
                                <div class="mt-0.5 text-xs text-muted">{{ $row['description'] }}</div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row['module'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            @if ($row['default'])
                                <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            @if ($row['enabled'])
                                <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            @if ($row['overridden'])
                                <x-ui.badge variant="warning">{{ __('Custom') }}</x-ui.badge>
                            @else
                                <span class="text-sm text-muted">{{ __('Default') }}</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                            @if ($canManage)
                                <div class="inline-flex items-center gap-2 justify-end">
                                    <x-ui.button
                                        variant="secondary"
                                        size="sm"
                                        wire:click="toggle({{ Js::from($row['flag']) }}, {{ $row['enabled'] ? 'false' : 'true' }})"
                                    >
                                        {{ $row['enabled'] ? __('Turn off') : __('Turn on') }}
                                    </x-ui.button>
                                    @if ($row['overridden'])
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            wire:click="clearOverride({{ Js::from($row['flag']) }})"
                                        >
                                            {{ __('Use default') }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-muted">{{ __('View only') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('No declared feature flags match this tenant.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <h3 class="mb-1 text-sm font-medium text-ink">{{ __('Overrides history') }}</h3>
            <p class="mb-4 text-sm text-muted">{{ __('Recent override mutations for this tenant only. Viewers can read the trail; only managers can change flags.') }}</p>

            @forelse ($overrideHistory as $flag => $entries)
                <div class="mb-6 last:mb-0" wire:key="feature-flag-history-{{ $flag }}">
                    <h4 class="mb-2 font-mono text-sm font-medium text-ink">{{ $flag }}</h4>
                    <x-ui.table container="flush" :caption="__('Override history for :flag', ['flag' => $flag])">
                        <x-slot name="head">
                            <tr>
                                <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('When') }}</th>
                                <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Actor') }}</th>
                                <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Tenant') }}</th>
                                <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('From') }}</th>
                                <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('To') }}</th>
                            </tr>
                        </x-slot>
                        @foreach ($entries as $entry)
                            <tr wire:key="feature-flag-history-{{ $flag }}-{{ $loop->index }}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if ($entry['occurred_at'])
                                        <x-ui.datetime :value="$entry['occurred_at']" />
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $entry['actor'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $entry['tenant_id'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                                    @if ($entry['old_enabled'] === null)
                                        <span class="text-muted">{{ __('—') }}</span>
                                    @elseif ($entry['old_enabled'])
                                        <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                                    @if ($entry['event'] === 'deleted')
                                        <span class="text-muted">{{ __('Default') }}</span>
                                    @elseif ($entry['new_enabled'] === null)
                                        <span class="text-muted">{{ __('—') }}</span>
                                    @elseif ($entry['new_enabled'])
                                        <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No override changes recorded for this tenant yet.') }}</p>
            @endforelse
        </x-ui.card>
    </div>
</div>
