{{-- Every registered icon as a referenceable <g>, rendered once per full page
     load in the authenticated app layout. <x-icon> then emits a tiny <use>
     instead of inlining path data per usage (587 inline SVGs / ~370 KB on a
     typical page before this). Guests and mail render inline — see icon.blade.php.
     Positioned off-screen rather than display:none: some engines refuse to
     resolve <use> into display:none subtrees. --}}
<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
    style="position: absolute; width: 0; height: 0; overflow: hidden">
    <defs>
        @foreach (\App\Base\Foundation\View\IconRegistry::PATHS as $name => $icon)
            <g id="blb-icon-{{ $name }}">
                @foreach (is_array($icon['path']) ? $icon['path'] : [$icon['path']] as $d)
                    <path stroke-linecap="round" stroke-linejoin="round" {!! $icon['fill'] === 'currentColor' ? 'fill-rule="evenodd" clip-rule="evenodd"' : '' !!} d="{{ $d }}" />
                @endforeach
            </g>
        @endforeach
    </defs>
</svg>
