@props(['name'])

@php
use App\Base\Foundation\View\IconRegistry;

$icon = IconRegistry::resolve($name);
$stroke = $icon['fill'] === 'none' ? 'stroke="currentColor" stroke-width="1.5"' : 'stroke="none"';
@endphp

<svg {{ $attributes }} xmlns="http://www.w3.org/2000/svg" viewBox="{{ $icon['viewBox'] }}" fill="{{ $icon['fill'] }}" {!! $stroke !!} aria-hidden="true">
    @if(is_array($icon['path']))
        @foreach($icon['path'] as $d)
            <path stroke-linecap="round" stroke-linejoin="round" {!! $icon['fill'] === 'currentColor' ? 'fill-rule="evenodd" clip-rule="evenodd"' : '' !!} d="{{ $d }}" />
        @endforeach
    @else
        <path stroke-linecap="round" stroke-linejoin="round" {!! $icon['fill'] === 'currentColor' ? 'fill-rule="evenodd" clip-rule="evenodd"' : '' !!} d="{{ $icon['path'] }}" />
    @endif
</svg>
