<div>
    <x-slot name="title">{{ __('Menu Inspector') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Menu Inspector')"
            :subtitle="__('Every registered menu item with its source, parent, permission, condition, and computed visibility for the current user.')"
        />

        <x-ui.card>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mb-3">
                <div class="md:col-span-5">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search id, label, permission, source file…') }}"
                    />
                </div>
                <div class="md:col-span-3">
                    <select
                        wire:model.live="sourceFilter"
                        class="w-full rounded-md border border-border-default bg-surface-card px-3 py-2 text-sm text-ink"
                    >
                        <option value="all">{{ __('All sources') }}</option>
                        @foreach ($sources as $src)
                            <option value="{{ $src }}">{{ $src }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <select
                        wire:model.live="kindFilter"
                        class="w-full rounded-md border border-border-default bg-surface-card px-3 py-2 text-sm text-ink"
                    >
                        <option value="all">{{ __('Core + Extensions') }}</option>
                        <option value="core">{{ __('Core only') }}</option>
                        <option value="extension">{{ __('Extensions only') }}</option>
                    </select>
                </div>
                <div class="md:col-span-2 flex items-center justify-end">
                    <span class="text-sm text-muted tabular-nums">
                        {{ count($rows) }} / {{ $totalCount }}
                    </span>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('Menu items')">

                <x-slot name="head">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('ID') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Parent') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Permission') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Condition') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Source') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Visible') }}</th>
                        </tr>
                    </x-slot>

                        @forelse ($rows as $row)
                            <tr wire:key="menu-{{ $row['id'] }}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-ink">
                                    {{ $row['id'] }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                                    {{ $row['label'] }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-muted">
                                    {{ $row['parent'] ?? '—' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-xs">
                                    @if ($row['isContainer'])
                                        <x-ui.badge variant="default">{{ __('container') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('leaf') }}</x-ui.badge>
                                    @endif
                                    @if ($row['isExtension'])
                                        <x-ui.badge variant="warning">{{ __('extension') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-muted">
                                    {{ $row['permission'] ?? '—' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-muted">
                                    {{ $row['condition'] ?? '—' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                                    @if ($row['sourceModule'])
                                        <div class="text-ink">{{ $row['sourceModule'] }}</div>
                                    @endif
                                    @if ($row['sourceFile'])
                                        <div class="font-mono text-[11px]">{{ $row['sourceFile'] }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-xs">
                                    @if ($row['visibleToCurrentUser'])
                                        <x-ui.badge variant="success">{{ __('yes') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="danger">{{ __('no') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                    {{ __('No menu items match the current filters.') }}
                                </td>
                            </tr>
                        @endforelse


            </x-ui.table>
        </x-ui.card>
    </div>
</div>
