<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<link rel="icon" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
<link rel="manifest" href="/site.webmanifest">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />{{-- NOSONAR — SRI not feasible for dynamic font CSS from Bunny Fonts CDN --}}

<script>window.__BLB_BROADCAST_DRIVER__=@json(config('broadcasting.default'));</script>
@vite(['resources/app.css', 'resources/core/js/app.js'])
