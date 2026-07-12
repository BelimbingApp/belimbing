@props(['name'])

@php
use App\Base\Foundation\View\IconRegistry;

$icon = IconRegistry::resolve($name);
$resolvedName = IconRegistry::has($name) ? $name : IconRegistry::FALLBACK;
$stroke = $icon['fill'] === 'none' ? 'stroke="currentColor" stroke-width="1.5"' : 'stroke="none"';

// Authenticated pages (and their Livewire updates) render inside the app
// layout, which carries the full icon sprite (partials/icon-sprite) — so a
// tiny <use> replaces inlined path data per usage. Guest pages and
// queue-rendered views (mail) have no sprite and keep the self-contained
// inline form.
$useSprite = auth()->check();
@endphp

<svg {{ $attributes }} xmlns="http://www.w3.org/2000/svg" viewBox="{{ $icon['viewBox'] }}" fill="{{ $icon['fill'] }}" {!! $stroke !!} aria-hidden="true">
    @if($useSprite)
        <use href="#blb-icon-{{ $resolvedName }}" />
    @else
        @foreach(is_array($icon['path']) ? $icon['path'] : [$icon['path']] as $d)
            <path stroke-linecap="round" stroke-linejoin="round" {!! $icon['fill'] === 'currentColor' ? 'fill-rule="evenodd" clip-rule="evenodd"' : '' !!} d="{{ $d }}" />
        @endforeach
    @endif
</svg>
