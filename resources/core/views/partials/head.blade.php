<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<link rel="icon" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
<link rel="manifest" href="/site.webmanifest">

@php
    $initialTheme = 'system';

    if (auth()->check()) {
        $user = auth()->user();
        $initialTheme = (string) app(\App\Base\Settings\Contracts\SettingsService::class)->get(
            'ui.theme',
            \App\Base\Settings\DTO\Scope::user((int) $user->getKey(), $user->getCompanyId()),
        );
    }
@endphp
<script>
    (() => {
        const serverTheme = @json($initialTheme);
        const theme = ['light', 'dark', 'system'].includes(serverTheme) ? serverTheme : 'system';
        localStorage.setItem('theme', theme);
        document.documentElement.classList.toggle(
            'dark',
            theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches),
        );
    })();
</script>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />{{-- NOSONAR — SRI not feasible for dynamic font CSS from Bunny Fonts CDN --}}

<script>window.__BLB_BROADCAST_DRIVER__=@json(config('broadcasting.default'));window.__BLB_LOGIN_URL__=@json(route('login'));</script>
@vite(['resources/app.css', 'resources/core/js/app.js'])
