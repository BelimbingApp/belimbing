<?php

return [
    'disk' => env('BLB_PDF_DISK', 'local'),
    'artifact_directory' => env('BLB_PDF_ARTIFACT_DIR', 'pdf-artifacts'),
    'signed_url_ttl_seconds' => (int) env('BLB_PDF_SIGNED_URL_TTL', 60),
    'render_timeout_seconds' => (int) env('BLB_PDF_RENDER_TIMEOUT', 30),
    'token_cache_store' => env('BLB_PDF_TOKEN_CACHE_STORE', null),
    'paper' => [
        'format' => env('BLB_PDF_PAPER_FORMAT', 'A4'),
        'print_background' => (bool) env('BLB_PDF_PRINT_BACKGROUND', true),
    ],
];
