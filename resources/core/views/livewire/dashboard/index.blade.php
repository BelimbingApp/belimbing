@php /** @var \App\Base\Dashboard\Livewire\Index $this */ @endphp
<div>
    <x-slot name="title">{{ __('Dashboard') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Dashboard')">
            <x-slot name="actions">
                @if($editing)
                    @if($hasCustomLayout)
                        <x-ui.button variant="ghost" size="sm" wire:click="resetLayout">
                            {{ __('Reset to default') }}
                        </x-ui.button>
                    @endif
                    <x-ui.button variant="primary" size="sm" wire:click="toggleEditing">
                        {{ __('Done') }}
                    </x-ui.button>
                @else
                    <x-ui.button variant="outline" size="sm" wire:click="toggleEditing">
                        <x-icon name="heroicon-o-squares-plus" class="w-4 h-4" />
                        {{ __('Customize') }}
                    </x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        @if($widgets === [] && ! $editing)
            <x-ui.card>
                @if($available === [])
                    <p class="text-sm text-muted">
                        {{ __('Nothing to show yet. Widgets appear here as business modules are installed and you gain access to them.') }}
                    </p>
                @else
                    <p class="text-sm text-muted">
                        {{ __('Your dashboard is empty. Use Customize to add widgets.') }}
                    </p>
                @endif
            </x-ui.card>
        @endif

        {{-- Dense flow lets 1-column widgets backfill the gap beside a
             2-column one, so narrow cards pack to the right instead of
             leaving holes. Order still follows the user's layout; packing
             only pulls a later narrow widget up when a slot would go empty. --}}
        <div class="grid gap-6 md:grid-cols-3 md:grid-flow-row-dense">
            @foreach($widgets as $widget)
                <div
                    wire:key="widget-{{ $widget->id }}"
                    @class([
                        'md:col-span-1' => $widget->size === 1,
                        'md:col-span-2' => $widget->size === 2,
                        'md:col-span-3' => $widget->size === 3,
                    ])
                >
                    @if($editing)
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="inline-flex min-w-0 items-center gap-1.5 text-[11px] uppercase tracking-wider font-semibold text-muted">
                                <x-icon :name="$widget->icon" class="w-3.5 h-3.5 shrink-0" />
                                <span class="truncate">{{ __($widget->label) }}</span>
                            </span>
                            <x-ui.icon-action-group>
                                <x-ui.icon-action
                                    icon="heroicon-o-arrow-up"
                                    :label="__('Move :widget earlier', ['widget' => __($widget->label)])"
                                    wire:click="moveUp('{{ $widget->id }}')"
                                    @disabled($loop->first)
                                />
                                <x-ui.icon-action
                                    icon="heroicon-o-arrow-down"
                                    :label="__('Move :widget later', ['widget' => __($widget->label)])"
                                    wire:click="moveDown('{{ $widget->id }}')"
                                    @disabled($loop->last)
                                />
                                <x-ui.icon-action
                                    icon="heroicon-o-x-mark"
                                    :label="__('Remove :widget from dashboard', ['widget' => __($widget->label)])"
                                    wire:click="remove('{{ $widget->id }}')"
                                />
                            </x-ui.icon-action-group>
                        </div>
                    @endif

                    <livewire:is :component="$widget->component" lazy :key="'w-'.$widget->id" />
                </div>
            @endforeach
        </div>

        @if($editing)
            <x-ui.card>
                <div class="mb-3">
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Add Widgets') }}</span>
                </div>
                @if($available === [])
                    <p class="text-sm text-muted">{{ __('All widgets available to you are already on your dashboard.') }}</p>
                @else
                    <ul class="divide-y divide-border-default">
                        @foreach($available as $widget)
                            <li wire:key="available-{{ $widget->id }}" class="flex items-center justify-between gap-4 py-2.5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <x-icon :name="$widget->icon" class="w-5 h-5 shrink-0 text-muted" />
                                    <div class="min-w-0">
                                        <p class="text-sm text-ink">{{ __($widget->label) }}</p>
                                        @if($widget->description)
                                            <p class="truncate text-xs text-muted">{{ __($widget->description) }}</p>
                                        @endif
                                    </div>
                                </div>
                                <x-ui.button variant="outline" size="sm" wire:click="add('{{ $widget->id }}')">
                                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                                    {{ __('Add') }}
                                </x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif
    </div>
</div>
