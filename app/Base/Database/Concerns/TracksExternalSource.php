<?php

namespace App\Base\Database\Concerns;

trait TracksExternalSource
{
    protected const EXTERNAL_SOURCE_FILLABLE = [
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected const EXTERNAL_SOURCE_CASTS = [
        'metadata' => 'array',
    ];
}
