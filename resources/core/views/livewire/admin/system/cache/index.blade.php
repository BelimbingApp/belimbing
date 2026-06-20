<div>
    <x-slot name="title">{{ __('Cache') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Cache')" :subtitle="__('View cache configuration and manage cache')" />

        <x-ui.session-flash />

        <x-ui.card>
            <h3 class="text-sm font-medium text-ink mb-3">{{ __('Cache Configuration') }}</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div>
                    <dt class="text-muted">{{ __('Driver') }}</dt>
                    <dd class="text-ink font-medium">{{ $driver }}</dd>
                </div>
                @foreach ($storeConfig as $key => $value)
                    <div>
                        <dt class="text-muted">{{ Str::headline($key) }}</dt>
                        <dd class="text-ink font-medium">
                            @if (is_bool($value))
                                {{ $value ? __('Yes') : __('No') }}
                            @elseif (is_null($value))
                                <span class="text-muted italic">{{ __('null') }}</span>
                            @elseif (is_array($value))
                                <span class="text-muted italic">{{ __('Array') }}</span>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-sm font-medium text-ink mb-3">{{ __('Actions') }}</h3>
            <div class="flex flex-wrap gap-3">
                <x-ui.button
                    variant="danger"
                    wire:click="flushAll"
                    wire:confirm="{{ __('Are you sure you want to flush all cache? This cannot be undone.') }}"
                >
                    {{ __('Flush All Cache') }}
                </x-ui.button>

                <x-ui.button
                    variant="secondary"
                    wire:click="clearMenuCache"
                    wire:confirm="{{ __('Are you sure you want to clear the menu cache?') }}"
                >
                    {{ __('Clear Menu Cache') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
