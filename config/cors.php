<?php

// Cross-Origin Resource Sharing policy read by the framework's HandleCors
// middleware. Belimbing is a same-origin server-rendered app (web + Livewire),
// so cross-origin browser access is denied by default: no allowed origins.
//
// The framework's built-in default is `allowed_origins => ['*']` scoped to
// `api/*`. There are no `api/*` routes today, but shipping this explicit,
// closed policy means a future API route cannot silently inherit a wildcard
// origin. Grant access deliberately by listing exact origins in
// CORS_ALLOWED_ORIGINS (comma-separated) if a first-party client needs it.

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
), static fn (string $origin): bool => $origin !== ''));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Never combine credentialed requests with a wildcard origin. Only meaningful
    // when allowed_origins lists exact origins.
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
