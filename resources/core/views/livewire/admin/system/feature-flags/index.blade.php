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
                                        wire:click="toggle({{ Js::from($row['flag']) }})"
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
    </div>
</div>
