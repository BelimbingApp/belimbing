<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Tools\Catalog $this */
?>
<div>
    <x-ui.page-header :title="__('Tools')" :subtitle="__('Tools extend what Agents can do — they let AI take actions, query data, and interact with external systems beyond generating text.')">
        <x-slot name="help">
            <div class="space-y-3">
                <p>{{ __('This page shows all Agent tools registered in Belimbing. Each tool is a capability that a agent can use during conversations or automated tasks.') }}</p>

                <div>
                    <p class="font-medium text-ink">{{ __('Readiness') }}</p>
                    <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                        <li>{{ __('Ready — tool is configured and the current user has access.') }}</li>
                        <li>{{ __('Unconfigured — tool needs setup (API keys, dependencies) before it can work.') }}</li>
                        <li>{{ __('Unauthorized — tool exists but you don\'t have permission to use it.') }}</li>
                        <li>{{ __('Unavailable — tool is not registered in this environment.') }}</li>
                    </ul>
                </div>

                <div>
                    <p class="font-medium text-ink">{{ __('Risk Classes') }}</p>
                    <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                        <li>{{ __('Read-only — can only read data, no side effects.') }}</li>
                        <li>{{ __('Internal — modifies internal Belimbing state (notifications, schedules).') }}</li>
                        <li>{{ __('External I/O — makes requests to external services.') }}</li>
                        <li>{{ __('Browser — interacts with web pages via headless browser.') }}</li>
                        <li>{{ __('Messaging — sends messages to external channels.') }}</li>
                        <li>{{ __('High-impact — powerful system access (shell, artisan, JS execution).') }}</li>
                    </ul>
                </div>

                <p>{{ __('Click any tool row to see its full description, configuration, and Try It console. Click column headers to sort.') }}</p>
            </div>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card>
        {{-- Filters --}}
        <div class="flex flex-col sm:flex-row gap-2 mb-3">
            <div class="flex-1">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search tools...') }}"
                />
            </div>
            <select
                wire:model.live="categoryFilter"
                class="rounded-xl border border-border-input bg-surface-card text-ink text-sm px-input-x py-input-y focus:ring-2 focus:ring-accent focus:ring-offset-2"
            >
                <option value="">{{ __('All Categories') }}</option>
                @foreach($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- Tool table --}}
        <div class="overflow-x-auto -mx-card-inner px-card-inner">
            <table class="min-w-full divide-y divide-border-default text-sm">
                <thead class="bg-surface-subtle/80">
                    <tr>
                        @php
                            $thBase = 'px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider select-none';
                            $thSortable = $thBase . ' cursor-pointer hover:text-ink transition-colors';
                            $thActive = 'text-ink';
                            $thInactive = 'text-muted';
                        @endphp

                        <th
                            wire:click="sortOn('name')"
                            title="{{ $sortBy === 'name' ? ($sortDir === 'asc' ? __('Sorted A→Z — click to reverse') : __('Sorted Z→A — click to reverse')) : __('Sort by name') }}"
                            class="{{ $thSortable }} {{ $sortBy === 'name' ? $thActive : $thInactive }}"
                        >
                            <span class="inline-flex items-center gap-1">
                                {{ __('Tool') }}
                                @if($sortBy === 'name')
                                    <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down' }}" class="w-3.5 h-3.5" />
                                @else
                                    <x-icon name="heroicon-o-chevron-up-down" class="w-3.5 h-3.5 opacity-40" />
                                @endif
                            </span>
                        </th>
                        <th
                            wire:click="sortOn('category')"
                            title="{{ $sortBy === 'category' ? ($sortDir === 'asc' ? __('Sorted by category — click to reverse') : __('Sorted by category (reversed) — click to restore')) : __('Sort by category') }}"
                            class="{{ $thSortable }} hidden md:table-cell {{ $sortBy === 'category' ? $thActive : $thInactive }}"
                        >
                            <span class="inline-flex items-center gap-1">
                                {{ __('Category') }}
                                @if($sortBy === 'category')
                                    <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down' }}" class="w-3.5 h-3.5" />
                                @else
                                    <x-icon name="heroicon-o-chevron-up-down" class="w-3.5 h-3.5 opacity-40" />
                                @endif
                            </span>
                        </th>
                        <th
                            wire:click="sortOn('risk')"
                            title="{{ $sortBy === 'risk' ? ($sortDir === 'asc' ? __('Sorted lowest risk first — click to reverse') : __('Sorted highest risk first — click to reverse')) : __('Sort by risk level') }}"
                            class="{{ $thSortable }} {{ $sortBy === 'risk' ? $thActive : $thInactive }}"
                        >
                            <span class="inline-flex items-center gap-1">
                                {{ __('Risk') }}
                                @if($sortBy === 'risk')
                                    <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down' }}" class="w-3.5 h-3.5" />
                                @else
                                    <x-icon name="heroicon-o-chevron-up-down" class="w-3.5 h-3.5 opacity-40" />
                                @endif
                            </span>
                        </th>
                        <th class="{{ $thBase }} {{ $thInactive }}">{{ __('Readiness') }}</th>
                        <th class="{{ $thBase }} {{ $thInactive }} hidden lg:table-cell">{{ __('Verified') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-surface-card divide-y divide-border-default">
                    @forelse($snapshots as $name => $snap)
                        @php
                            $meta = $snap['metadata'];
                            $readiness = $snap['readiness'];
                            $lastVerified = $snap['lastVerified'];
                        @endphp
                        <tr
                            wire:key="tool-{{ $name }}"
                            @click="Livewire.navigate('{{ route('admin.ai.tools', ['toolName' => $name]) }}')"
                            class="hover:bg-surface-subtle/50 transition-colors cursor-pointer"
                        >
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-ink">{{ $meta->displayName }}</div>
                                    <div class="text-xs text-muted truncate max-w-sm">{{ $meta->summary }}</div>
                                </div>
                            </td>
                            <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap">
                                <span class="text-xs text-muted">{{ $meta->category->label() }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                <x-ui.badge :variant="$meta->riskClass->color()">{{ $meta->riskClass->label() }}</x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                <x-ui.badge :variant="$readiness->color()">{{ $readiness->label() }}</x-ui.badge>
                            </td>
                            <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap">
                                @if($lastVerified)
                                    <x-ui.badge :variant="$lastVerified['success'] ? 'success' : 'danger'">
                                        {{ $lastVerified['success'] ? __('Passed') : __('Failed') }}
                                    </x-ui.badge>
                                @else
                                    <x-ui.badge variant="default">{{ __('—') }}</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-table-cell-x py-8 text-center">
                                <p class="text-sm text-muted">
                                    @if($search || $categoryFilter)
                                        {{ __('No tools match your filters.') }}
                                    @else
                                        {{ __('No tools registered.') }}
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
