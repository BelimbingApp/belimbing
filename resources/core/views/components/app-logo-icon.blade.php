<img
    src="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}"
    alt="{{ config('app.name') }}"
    width="20"
    height="20"
    decoding="async"
    loading="eager"
    {{ $attributes }}
/>
