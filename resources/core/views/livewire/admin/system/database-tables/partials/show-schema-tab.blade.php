@php
    $columnForeignKeys = collect($foreignKeys['outgoing'])->groupBy('column');
    $primaryIndex = collect($indexes)->firstWhere('primary', true);
    $nonPrimaryIndexes = collect($indexes)->reject(fn ($index) => $index['primary'])->values();
@endphp

<x-ui.card>
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Columns') }}</h2>
            <p class="text-sm text-muted">{{ __('Live database column metadata for this table.') }}</p>
        </div>
        <x-ui.badge variant="default">{{ trans_choice(':count column|:count columns', count($columns), ['count' => count($columns)]) }}</x-ui.badge>
    </div>

    <div class="overflow-x-auto -mx-card-inner px-card-inner">
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Column') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Null') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Default') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Attributes') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('References') }}</th>
                </tr>
            </thead>
            <tbody class="bg-surface-card divide-y divide-border-default">
                @foreach($columns as $col)
                    @php $foreignKeyLinks = $columnForeignKeys->get($col['name'], collect()); @endphp
                    <tr wire:key="schema-column-{{ $col['name'] }}" class="hover:bg-surface-subtle/50 transition-colors">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-ink">{{ $col['name'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-ink">{{ $col['type_name'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $col['nullable'] ? __('Yes') : __('No') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm font-mono {{ $col['default'] === null ? 'text-muted' : 'text-ink' }}">
                            {{ $col['default'] === null ? 'NULL' : (string) $col['default'] }}
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            <div class="flex flex-wrap gap-1.5">
                                @if($primaryIndex && in_array($col['name'], $primaryIndex['columns'], true))
                                    <x-ui.badge variant="default">{{ __('Primary') }}</x-ui.badge>
                                @endif
                                @if($col['auto_increment'])
                                    <x-ui.badge variant="default">{{ __('Auto increment') }}</x-ui.badge>
                                @endif
                                @foreach($nonPrimaryIndexes as $index)
                                    @if(in_array($col['name'], $index['columns'], true))
                                        <x-ui.badge :variant="$index['unique'] ? 'success' : 'default'">
                                            {{ $index['unique'] ? __('Unique') : __('Indexed') }}
                                        </x-ui.badge>
                                    @endif
                                @endforeach
                                @if((! $primaryIndex || ! in_array($col['name'], $primaryIndex['columns'], true)) && ! $col['auto_increment'] && $nonPrimaryIndexes->every(fn ($index) => ! in_array($col['name'], $index['columns'], true)))
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            @if($foreignKeyLinks->isNotEmpty())
                                <div class="flex flex-wrap gap-2">
                                    @foreach($foreignKeyLinks as $fk)
                                        <a
                                            href="{{ route('admin.system.database-tables.show', $fk['foreign_table']) }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1 rounded-full border border-border-default bg-surface-subtle px-2 py-0.5 font-mono text-xs text-link hover:bg-surface-card"
                                        >
                                            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3 h-3" />
                                            {{ $fk['foreign_table'] }}.{{ $fk['foreign_column'] }}
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-ui.card>

<x-ui.card>
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Indexes') }}</h2>
            <p class="text-sm text-muted">{{ __('Primary, unique, and secondary indexes currently present on this table.') }}</p>
        </div>
        <x-ui.badge variant="default">{{ trans_choice(':count index|:count indexes', count($indexes), ['count' => count($indexes)]) }}</x-ui.badge>
    </div>

    <div class="overflow-x-auto -mx-card-inner px-card-inner">
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Columns') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Flags') }}</th>
                </tr>
            </thead>
            <tbody class="bg-surface-card divide-y divide-border-default">
                @forelse($indexes as $index)
                    <tr wire:key="schema-index-{{ $index['name'] }}" class="hover:bg-surface-subtle/50 transition-colors">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-ink">{{ $index['name'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-ink">{{ implode(', ', $index['columns']) }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ str_replace('_', ' ', $index['type']) }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            <div class="flex flex-wrap gap-1.5">
                                @if($index['primary'])
                                    <x-ui.badge variant="default">{{ __('Primary') }}</x-ui.badge>
                                @endif
                                @if($index['unique'])
                                    <x-ui.badge variant="success">{{ __('Unique') }}</x-ui.badge>
                                @endif
                                @if(! $index['primary'] && ! $index['unique'])
                                    <span class="text-muted">{{ __('Secondary') }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('No index metadata is available for this table.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@if($migrationSource)
    <x-ui.card id="migration-source">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Migration source') }}</h2>
                <p class="text-sm text-muted">{{ __('The migration file that originally registered this table in Belimbing.') }}</p>
            </div>
            <span class="text-xs font-mono text-muted">{{ $migrationSource['file_name'] }}</span>
        </div>

        <div class="rounded-2xl border border-border-default bg-surface-subtle/70">
            <div class="border-b border-border-default px-4 py-2 text-xs font-mono text-muted">{{ $migrationSource['relative_path'] }}</div>
            <pre class="max-h-[32rem] overflow-auto p-4 text-xs leading-6 text-ink"><code>{{ $migrationSource['contents'] }}</code></pre>
        </div>
    </x-ui.card>
@endif
