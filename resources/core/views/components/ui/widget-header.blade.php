{{--
    Dashboard widget header: uppercase title left; one same-size affordance
    top-right — an icon-only open action when the widget has a destination,
    or a decorative icon when it does not. Widgets must not hand-roll this
    row: the dashboard reads as one system only when every card carries the
    identical header rhythm.
--}}
@props([
    'title',
    'href' => null,      // in-app destination; renders the open icon-action
    'openLabel' => null, // accessible label for the open action
    'icon' => null,      // decorative icon when there is no destination
])

<div {{ $attributes->class(['mb-3 flex items-center justify-between gap-2']) }}>
    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ $title }}</span>
    @if($href !== null)
        <x-ui.icon-action
            icon="heroicon-m-arrow-right"
            :label="$openLabel ?? __('Open :title', ['title' => $title])"
            :href="$href"
            wire:navigate
        />
    @elseif($icon !== null)
        <x-icon :name="$icon" class="w-4 h-4 text-muted" />
    @endif
</div>
