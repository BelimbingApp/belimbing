<?php

return [
    'disk' => env('BLB_PDF_DISK', 'local'),
    'artifact_directory' => env('BLB_PDF_ARTIFACT_DIR', 'pdf-artifacts'),
    'signed_url_ttl_seconds' => 60,
    'render_timeout_seconds' => 30,
    'token_cache_store' => env('BLB_PDF_TOKEN_CACHE_STORE', null),
    'paper' => [
        'format' => 'A4',
        'print_background' => true,
    ],
    'qpdf' => [
        'binary' => env('BLB_PDF_QPDF_BINARY'),
        'timeout_seconds' => 60,
    ],
];
