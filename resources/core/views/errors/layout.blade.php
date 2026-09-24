{{--
    Standalone error page shell. Deliberately self-contained: no Vite, no
    app layout, no Livewire, no session, no database — an error page must
    render even when the asset pipeline or application boot is the thing
    that broke. Colors mirror resources/core/css/tokens.css (arid palette,
    zinc in dark mode); the logo is the favicon inlined so no request leaves
    the page. `blb:error-pages:publish` renders this same shell into the
    static fallback that Caddy and the CDN serve when PHP cannot answer.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('app.name', 'Belimbing') }}</title>
    @yield('head')
    <style>
        :root {
            --surface-page: #f1ede5;
            --surface-card: #faf9f5;
            --border-default: #ddd8cf;
            --ink: #2c2418;
            --muted: #6b6057;
            --accent: #b5622f;
            --accent-hover: #9a5226;
            --accent-on: #ffffff;
            color-scheme: light dark;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --surface-page: #0a0a0a;
                --surface-card: #171717;
                --border-default: #262626;
                --ink: #f5f5f5;
                --muted: #a3a3a3;
                --accent: #c97a55;
                --accent-hover: #b5622f;
            }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            background: var(--surface-page);
            color: var(--ink);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
        }
        main {
            background: var(--surface-card);
            border: 1px solid var(--border-default);
            border-radius: 1rem;
            padding: 2.5rem;
            max-width: 26rem;
            width: 100%;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            margin-bottom: 1.75rem;
            font-weight: 600;
            font-size: 0.9375rem;
            letter-spacing: 0.01em;
        }
        .brand svg { width: 1.75rem; height: auto; flex: none; }
        .code {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
        }
        h1 { font-size: 1.25rem; font-weight: 600; margin-top: 0.5rem; }
        p { font-size: 0.875rem; line-height: 1.5rem; color: var(--muted); margin-top: 0.75rem; }
        .actions { margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; }
        a.button {
            display: inline-block;
            background: var(--accent);
            color: var(--accent-on);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
        }
        a.button:hover { background: var(--accent-hover); }
        a.quiet {
            display: inline-block;
            color: var(--muted);
            font-size: 0.875rem;
            padding: 0.5rem 0.25rem;
        }
        .diagnostic {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-default);
            font-size: 0.75rem;
            line-height: 1.25rem;
            color: var(--muted);
        }
    </style>
</head>
<body>
    @php
        // A link back to the exact page already on screen is not a fix, it's
        // a reload — say so instead of promising an escape that isn't one
        // (the screenshot that prompted this: 500 on "/", "Back to home"
        // pointing at "/" too, so clicking it just replayed the same 500).
        $currentUrl = url()->current();
        $primaryHref = $__env->yieldContent('primary-href', url('/'));
        $primaryIsHere = $primaryHref === $currentUrl;
        $secondaryHref = $__env->yieldContent('secondary-href', '');
        $secondaryIsHere = $secondaryHref !== '' && $secondaryHref === $currentUrl;

        // The favicon is the brand mark. Inline it so the page needs nothing
        // else from the server; an error page cannot assume static files load.
        $logoPath = public_path('favicon.svg');
        $logoSvg = is_file($logoPath) ? (string) file_get_contents($logoPath) : '';
    @endphp
    <main>
        <div class="brand">
            {!! $logoSvg !!}
            <span>{{ config('app.name', 'Belimbing') }}</span>
        </div>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <a class="button" href="@yield('primary-href', url('/'))">{{ $primaryIsHere ? __('Try again') : $__env->yieldContent('primary-label', __('Back to home')) }}</a>
            @if($secondaryHref !== '' && ! $secondaryIsHere)
                <a class="quiet" href="{{ $secondaryHref }}">@yield('secondary-label')</a>
            @endif
        </div>
        @hasSection('diagnostic')
            <div class="diagnostic">@yield('diagnostic')</div>
        @endif
    </main>
</body>
</html>
