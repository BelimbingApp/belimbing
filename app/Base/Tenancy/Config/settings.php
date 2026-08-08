<?php

return [
    'definitions' => [
        'tenancy.show_management' => [
            'type' => 'boolean',
            'scopes' => ['global'],
            'default' => false,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['boolean'],
            'label' => 'Show tenant management',
            'help' => 'Reveal tenant management in Administration even while this instance hosts a single tenant.',
        ],
    ],
];
