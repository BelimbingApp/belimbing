<?php

return [
    'editable' => [
        'commerce' => [
            'label' => 'Commerce',
            'description' => 'Company-level defaults used by Commerce records before channel-specific data exists.',
            'fields' => [
                [
                    'key' => 'commerce.default_currency_code',
                    'label' => 'Default currency',
                    'type' => 'select',
                    'scope' => 'company',
                    'default' => 'MYR',
                    'options' => [],
                    'help' => 'Used for new Commerce records. Options come from Geonames country data.',
                    'rules' => ['required', 'string', 'size:3'],
                    'normalize' => 'uppercase',
                ],
            ],
        ],
    ],
];
