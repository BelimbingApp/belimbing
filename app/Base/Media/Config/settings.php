<?php

use App\Base\Media\PhotoCleanup\PhotoCleanupSelection;

return [
    'definitions' => [
        PhotoCleanupSelection::SETTING_KEY => [
            'type' => 'string',
            'scopes' => ['company'],
            'default' => PhotoCleanupSelection::DEFAULT_PROVIDER,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'string', 'max:64'],
            'label' => 'Photo cleanup provider',
            'help' => 'Provider used to process product-photo cleanup requests for this company.',
            'editable' => 'commerce.inventory.photo-cleanup',
            'capability' => 'commerce.inventory.manage',
        ],
    ],
];
