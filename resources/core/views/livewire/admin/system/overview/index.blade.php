<div class="space-y-section-gap">
    <x-slot name="title">{{ __('System overview') }}</x-slot>

    <x-ui.page-header
        :title="__('System overview')"
        :subtitle="__('Headline counts for each operator surface you can open. This page does not edit anything.')"
    />

    @if ($cards === [])
        <x-ui.alert variant="info">{{ __('No operator surfaces are visible with your current capabilities.') }}</x-ui.alert>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                <x-ui.card wire:key="system-overview-{{ $card['key'] }}" data-overview-card="{{ $card['key'] }}">
                    <div class="space-y-3">
                        <h2 class="text-sm font-medium text-ink">{{ $card['title'] }}</h2>
                        <p class="text-3xl font-semibold tabular-nums text-ink" data-overview-count="{{ $card['key'] }}">
                            {{ number_format($card['count']) }}
                        </p>
                        <p class="text-sm text-muted">{{ $card['count_label'] }}</p>
                        <x-ui.link :href="$card['href']">{{ __('Open :surface', ['surface' => $card['title']]) }}</x-ui.link>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
