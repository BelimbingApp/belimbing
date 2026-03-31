<div>
    <x-slot name="title">{{ __('Countries') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Countries')">
            <x-slot name="actions">
                <x-ui.button wire:click="update" wire:loading.attr="disabled" wire:target="update">
                    <x-icon name="heroicon-o-arrow-path" class="w-5 h-5 shrink-0" wire:loading.class="animate-spin" wire:target="update" />
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
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by country name or ISO code...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <colgroup>
                        <col>
                        <col>
                        <col>
                        <col>
                        <col>
                        <col style="width: 7rem;">
                        <col style="width: 6rem;">
                    </colgroup>
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('ISO') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                <button type="button" wire:click="sort('country')" class="inline-flex items-center gap-1 hover:text-ink transition-colors">
                                    {{ __('Country') }}
                                    @if($sortBy === 'country')
                                        <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3" />
                                    @else
                                        <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 opacity-40" />
                                    @endif
                                </button>
                            </th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Capital') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Phone') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Currency') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider pr-3">
                                <button type="button" wire:click="sort('population')" class="inline-flex items-center gap-1 ml-auto hover:text-ink transition-colors">
                                    @if($sortBy === 'population')
                                        <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3" />
                                    @else
                                        <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 opacity-40" />
                                    @endif
                                    {{ __('Population') }}
                                </button>
                            </th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider pl-3">{{ __('Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($countries as $country)
                            <tr wire:key="country-{{ $country->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-medium text-ink tabular-nums">{{ $country->iso }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-ink"
                                    x-data="{ editing: false, name: '{{ addslashes($country->country) }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span x-text="name"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="name"
                                        @keydown.enter="editing = false; $wire.saveName({{ $country->id }}, name)"
                                        @keydown.escape="editing = false; name = '{{ addslashes($country->country) }}'"
                                        @blur="editing = false; $wire.saveName({{ $country->id }}, name)"
                                        type="text"
                                        class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $country->capital }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">{{ $country->phone }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted">{{ $country->currency_code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted text-right tabular-nums pr-3">{{ app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($country->population) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums pl-3"><x-ui.datetime :value="$country->updated_at" format="date" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-muted">{{ __('No countries found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $countries->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
