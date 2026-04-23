@props([
    'title',
    'component' => null,
])

<div {{ $attributes }}>
    <h2 class="text-sm font-medium text-ink">{{ $title }}</h2>

    @if($component)
        <p class="text-xs text-muted">{!! __('Component: :component', ['component' => $component]) !!}</p>
    @endif

    @if(! $slot->isEmpty())
        <p class="text-xs text-muted">{{ $slot }}</p>
    @endif
</div>

