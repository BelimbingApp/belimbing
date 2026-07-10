<?php

use App\Base\Database\Livewire\Bridge\Index;

/** @var Index $this */
?>

<div>
    <x-slot name="title">{{ __('Data Bridge') }}</x-slot>

    <x-ui.page-header
        :title="__('Data Bridge')"
        :subtitle="__('Diagnostic row capture packages for development-only import.')"
    />

    <div class="mt-4 space-y-section-gap">
        @if($statusMessage)
            <x-ui.alert :variant="$statusVariant ?? 'info'">
                {{ $statusMessage }}
            </x-ui.alert>
        @endif

        <x-ui.alert variant="info">
            {{ __('Captured string values preserve their exact bytes. Packages are stored unencrypted on the private :disk disk under :prefix. Identified secrets are redacted, but remaining values may still be sensitive. Only a development instance will import the diagnostic marker.', ['disk' => $diskName, 'prefix' => $pathPrefix]) }}
        </x-ui.alert>

        <x-ui.card>
            @if($packages === [])
                <div class="py-8 text-center">
                    <p class="text-sm font-medium text-ink">{{ __('Capture your first rows') }}</p>
                    <p class="mt-1 text-sm text-muted">
                        {{ __('Open a table under Database Tables, select rows, and choose Preview package to create a diagnostic Data Bridge package.') }}
                    </p>
                    <div class="mt-3">
                        <x-ui.button as="a" size="sm" href="{{ route('admin.system.database-tables.index') }}" wire:navigate>
                            <x-icon name="heroicon-o-table-cells" class="w-4 h-4" />
                            {{ __('Browse tables') }}
                        </x-ui.button>
                    </div>
                </div>
            @else
                <x-ui.table container="flush" :caption="__('Diagnostic capture packages')">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Package') }}</x-ui.th>
                            <x-ui.th>{{ __('Root table') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Selected') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Tables') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Rows') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Size') }}</x-ui.th>
                            <x-ui.th>{{ __('Payload SHA-256') }}</x-ui.th>
                            <x-ui.th>{{ __('Created') }}</x-ui.th>
                            @if($canDelete)
                                <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                            @endif
                        </tr>
                    </x-slot>
                    @foreach($packages as $package)
                        <tr wire:key="{{ $package['package_id'] }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-ink" title="{{ $package['path'] }}">
                                {{ $package['package_id'] }}
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm font-mono">
                                <x-ui.link kind="internal" href="{{ route('admin.system.database-tables.show', $package['root_table']) }}">
                                    {{ $package['root_table'] }}
                                </x-ui.link>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink text-right tabular-nums">{{ $package['selected'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink text-right tabular-nums">{{ $package['tables'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink text-right tabular-nums">{{ $package['rows'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink text-right tabular-nums">
                                {{ app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($package['size_bytes']) }} B
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-muted">{{ $package['payload_sha256_short'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-muted whitespace-nowrap tabular-nums">
                                <x-ui.datetime :value="$package['created_at']" />
                            </td>
                            @if($canDelete)
                                <td class="px-table-cell-x py-table-cell-y text-right">
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        wire:click="deletePackage(@js($package['path']))"
                                        wire:confirm="{{ __('Delete this diagnostic package?') }}"
                                    >
                                        <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                        {{ __('Delete') }}
                                    </x-ui.button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</div>
