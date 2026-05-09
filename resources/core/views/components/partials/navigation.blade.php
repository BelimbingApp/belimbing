@props(['items' => []])

<ul class="flex flex-col space-y-1 w-full gap-1">
    @foreach($items as $item)
        <li>
            <a
                href="{{ $item['href'] ?? '#' }}"
                wire:navigate="{{ $item['wireNavigate'] ?? true }}"
                class="{{ ($item['route'] ?? null) && request()->routeIs($item['route']) ? 'active' : '' }} nav-link"
                @if(isset($item['target'])) target="{{ $item['target'] }}" @endif
            >
                @if(isset($item['icon']))
                    <x-icon :name="$item['icon']" class="w-5 h-5" />
                @endif
                <span>{{ $item['label'] ?? '' }}</span>
            </a>
        </li>
    @endforeach
</ul>

