<?php

// Security response headers applied by App\Base\Foundation\Http\Middleware\SecurityHeaders.
// Every value is env-tunable so an operator can tighten or relax a single header
// without a code change. The Content-Security-Policy is intentionally pragmatic:
// the TALL stack (Alpine, Livewire, Vite) needs 'unsafe-eval'/'unsafe-inline' for
// scripts and styles, so those stay open while the injection- and clickjacking-
// sensitive directives (object-src, base-uri, frame-ancestors, form-action) are
// locked down. Tightening script-src to nonces is tracked as a follow-up phase.

return [

    // Master switch. When false the middleware is a pass-through.
    'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

    // Static, always-safe headers. Sent on every response unless already present
    // on the response (a controller may set its own stricter value).
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),
        'Referrer-Policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'Permissions-Policy' => env(
            'SECURITY_PERMISSIONS_POLICY',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ),
    ],

    // HTTP Strict Transport Security. Off by default because the app also serves
    // plain http locally; enable once TLS is enforced in the target environment.
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'value' => env('SECURITY_HSTS_VALUE', 'max-age=31536000; includeSubDomains'),
    ],

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

        // Report-Only ships the policy as an observation channel (violations are
        // reported, nothing is blocked) — useful while tightening directives.
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        // Directive => list of sources. An empty list emits the bare directive
        // (e.g. upgrade-insecure-requests). Assembled in source order.
        'directives' => [
            'default-src' => ["'self'"],
            // 'unsafe-eval' — Alpine evaluates expressions via Function().
            // 'unsafe-inline' — the head bootstrap <script> and Livewire injection.
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
            // 'unsafe-inline' — Alpine :style/style="" attributes; Bunny Fonts CSS.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'font-src' => ["'self'", 'https://fonts.bunny.net', 'data:'],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ],
    ],

];
