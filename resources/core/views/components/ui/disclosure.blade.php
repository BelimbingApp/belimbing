@props([
    'title',
    'defaultOpen' => false,
    'variant' => 'section',
    'panelId' => null,
    'contentClass' => 'mt-4',
    'triggerClass' => null,
    'titleClass' => null,
    'chevronSizeClass' => null,
    'chevronBoxClass' => null,
])

<div x-data="{ open: @js($defaultOpen) }">
    <div>
        @php
            $defaultTriggerClass = $variant === 'card-header'
                ? 'group inline-flex items-center gap-2 rounded-2xl text-left text-sm font-medium text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2'
                : 'flex items-center gap-2 w-full text-left';

            $defaultTitleClass = $variant === 'card-header'
                ? ''
                : 'text-[11px] uppercase tracking-wider font-semibold text-muted';

            $defaultChevronSize = $variant === 'card-header' ? 'w-3.5 h-3.5' : 'w-3 h-3';
            $defaultChevronBox = $variant === 'card-header' ? 'w-3.5' : 'w-3';

            $resolvedTriggerClass = $triggerClass ?? $defaultTriggerClass;
            $resolvedTitleClass = $titleClass ?? $defaultTitleClass;
            $resolvedChevronSize = $chevronSizeClass ?? $defaultChevronSize;
            $resolvedChevronBox = $chevronBoxClass ?? $defaultChevronBox;
        @endphp

        <button
            type="button"
            @click="open = !open"
            :aria-expanded="open.toString()"
            @if($panelId) aria-controls="{{ $panelId }}" @endif
            class="{{ $resolvedTriggerClass }}"
        >
            <span class="shrink-0 text-muted {{ $resolvedChevronBox }} grid place-items-center" aria-hidden="true">
                <x-icon
                    name="heroicon-m-chevron-right"
                    x-show="!open"
                    x-cloak
                    class="{{ $resolvedChevronSize }}"
                />
                <x-icon
                    name="heroicon-m-chevron-down"
                    x-show="open"
                    x-cloak
                    class="{{ $resolvedChevronSize }}"
                />
            </span>

            @if ($variant === 'section')
                <h3 class="{{ $resolvedTitleClass }}">
                    {{ $title }}
                    {{ $badge ?? '' }}
                </h3>
            @else
                <span @if($resolvedTitleClass !== '') class="{{ $resolvedTitleClass }}" @endif>{{ $title }}</span>
                {{ $badge ?? '' }}
            @endif
        </button>

        @if (isset($hint))
            <div x-show="open" x-cloak class="mt-1">
                {{ $hint }}
            </div>
        @endif
    </div>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1"
        class="{{ $contentClass }}"
        style="display: none;"
        @if($panelId) id="{{ $panelId }}" @endif
    >
        {{ $slot }}
    </div>
</div>
