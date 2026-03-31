<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

<div>
    <x-slot name="title">{{ __('Logs') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Logs')" :subtitle="__('Application log files')" />

        <x-ui.card>
            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('File') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Size') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Last Modified') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($files as $file)
                            @php
                                $modifiedAt = \Carbon\Carbon::createFromTimestamp($file->getMTime());
                            @endphp
                            <tr wire:key="log-{{ $file->getFilename() }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium">
                                    <a href="{{ route('admin.system.logs.show', $file->getFilename()) }}" class="text-accent hover:underline" wire:navigate>
                                        {{ $file->getFilename() }}
                                    </a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ Number::fileSize($file->getSize()) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                    <div class="flex items-baseline gap-2 whitespace-nowrap">
                                        <span class="tabular-nums">
                                            <x-ui.datetime :value="$modifiedAt" />
                                        </span>
                                        <span class="text-xs text-muted">{{ $modifiedAt->diffForHumans() }}</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No log files found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</div>
