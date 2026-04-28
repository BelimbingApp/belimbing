<div>
    <x-slot name="title">{{ __('Admin1 Divisions') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Admin1 Divisions')" :subtitle="__('States, provinces, and top-level administrative divisions')">
            <x-slot name="actions">
                <x-ui.button wire:click="update" wire:loading.attr="disabled" wire:target="update">
                    <x-icon name="heroicon-o-arrow-path" class="w-5 h-5" wire:loading.class="animate-spin" wire:target="update" />
                    <span wire:loading.remove wire:target="update">{{ __('Update') }}</span>
                    <span wire:loading wire:target="update">{{ __('Updating...') }}</span>
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="flex flex-col sm:flex-row gap-3 mb-3">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by name, code, or country...') }}"
                    />
                </div>
                <div class="sm:w-64">
                    <x-ui.select id="filter-country-iso" wire:model.live="filterCountryIso">
                        <option value="">{{ __('All Countries') }}</option>
                        @foreach($importedCountries as $iso => $name)
                            <option value="{{ $iso }}">{{ $name }} ({{ $iso }})</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="country_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('country_name')"
                                :label="__('Country')"
                            />
                            <x-ui.sortable-th
                                column="code"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('code')"
                                :label="__('Code')"
                            />
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('name')"
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="alt_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('alt_name')"
                                :label="__('Alt Name')"
                            />
                            <x-ui.sortable-th
                                column="updated_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('updated_at')"
                                :label="__('Updated')"
                                class="min-w-22"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($admin1s as $admin1)
                            <tr wire:key="admin1-{{ $admin1->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <span class="font-mono text-xs text-muted">{{ $admin1->country_iso }}</span>
                                    <span class="ml-1">{{ $admin1->country_name }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-mono text-ink">{{ $admin1->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink"
                                    x-data="{ editing: false, name: '{{ addslashes($admin1->name) }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span x-text="name"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="name"
                                        @keydown.enter="editing = false; $wire.saveName({{ $admin1->id }}, name)"
                                        @keydown.escape="editing = false; name = '{{ addslashes($admin1->name) }}'"
                                        @blur="editing = false; $wire.saveName({{ $admin1->id }}, name)"
                                        type="text"
                                        class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $admin1->alt_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums min-w-22"><x-ui.datetime :value="$admin1->updated_at" format="date" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-12 text-center">
                                    <p class="text-sm text-muted">{{ __('No admin1 divisions found.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $admin1s->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
