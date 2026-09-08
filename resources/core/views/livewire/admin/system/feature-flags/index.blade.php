<?php

use App\Base\FeatureFlags\Livewire\Index;

/** @var Index $this */
?>
<div>
    <x-slot name="title">{{ __('Feature Flags') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Feature Flags')"
            :subtitle="__('Review mounted module declarations and tenant-specific overrides.')"
        />

        <x-ui.session-flash />

        <x-ui.tabs
            tabs-id="feature-flags-tabs"
            :tabs="[
                ['id' => 'overrides', 'label' => __('Tenant overrides')],
                ['id' => 'declared', 'label' => __('Declared flags')],
            ]"
            default="overrides"
        >
            <x-ui.tab id="overrides">
        @if ($hasDeclarationConflicts)
            <x-ui.alert variant="warning" class="mb-4">
                {{ __('Tenant override changes are read-only until duplicate flag declarations are resolved in their owning module manifests.') }}
            </x-ui.alert>
        @endif

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
                            {{ __('No available tenant overrides match your search.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <h3 class="mb-1 text-sm font-medium text-ink">{{ __('Orphaned overrides') }}</h3>
            <p class="mb-4 text-sm text-muted">
                {{ __('Override rows for tenant :tenant that no enabled module currently declares. Purge removes the stale row; declared flags still clear through Use default.', ['tenant' => $tenantId]) }}
            </p>

            <x-ui.table
                container="flush"
                :caption="__('Orphaned feature-flag overrides for tenant :tenant — not declared by any enabled module', ['tenant' => $tenantId])"
            >
                <x-slot name="head">
                    <tr>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Flag') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Stored') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Updated') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-right text-xs font-medium text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                    </tr>
                </x-slot>

                @forelse ($orphanedRows as $orphan)
                    <tr wire:key="feature-flag-orphan-{{ $orphan['flag'] }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $orphan['flag'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            @if ($orphan['enabled'])
                                <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                            @if ($orphan['updated_at'])
                                <x-ui.datetime :value="$orphan['updated_at']" />
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                            @if ($canManage)
                                <x-ui.button
                                    variant="secondary"
                                    size="sm"
                                    wire:click="purge({{ Js::from($orphan['flag']) }})"
                                >
                                    {{ __('Purge') }}
                                </x-ui.button>
                            @else
                                <span class="text-xs text-muted">{{ __('View only') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('No orphaned overrides for this tenant.') }}
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
            </x-ui.tab>

            <x-ui.tab id="declared">
                <x-ui.card>
                    <x-ui.table container="flush" :caption="__('Feature flags declared by mounted modules')">
                        <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('Flag') }}</x-ui.th>
                                <x-ui.th>{{ __('Owner module') }}</x-ui.th>
                                <x-ui.th>{{ __('Default') }}</x-ui.th>
                                <x-ui.th>{{ __('Description') }}</x-ui.th>
                                <x-ui.th>{{ __('Tenant override') }}</x-ui.th>
                            </tr>
                        </x-slot>

                        @forelse ($declaredRows as $row)
                            <tr wire:key="declared-feature-flag-{{ $row['flag'] }}">
                                <td class="px-table-cell-x py-table-cell-y align-top text-sm">
                                    <div class="font-medium text-ink">{{ $row['flag'] }}</div>
                                    @if ($row['conflict'])
                                        <x-ui.badge variant="danger" class="mt-1">{{ __('Conflict') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
                                    <div class="space-y-1">
                                        @foreach ($row['declarations'] as $declaration)
                                            <div>{{ $declaration['module'] }}</div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top whitespace-nowrap">
                                    <div class="space-y-1">
                                        @foreach ($row['declarations'] as $declaration)
                                            <div>
                                                @if ($declaration['default'])
                                                    <x-ui.badge variant="success">{{ __('On') }}</x-ui.badge>
                                                @else
                                                    <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top text-sm text-muted">
                                    <div class="space-y-1">
                                        @foreach ($row['declarations'] as $declaration)
                                            <div>{{ $declaration['description'] !== '' ? $declaration['description'] : __('No description') }}</div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top whitespace-nowrap">
                                    @if (! $row['overridden'])
                                        <span class="text-sm text-muted">{{ __('None') }}</span>
                                    @elseif ($row['override_enabled'])
                                        <x-ui.badge variant="warning">{{ __('Overridden on') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">{{ __('Overridden off') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                    {{ __('No mounted module declarations match your search.') }}
                                </td>
                            </tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>
