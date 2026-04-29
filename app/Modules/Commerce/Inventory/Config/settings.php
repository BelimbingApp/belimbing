<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'editable' => [
        'commerce' => [
            'label' => 'Commerce defaults',
            'description' => 'Company-level defaults used by Commerce records before channel-specific data exists.',
            'fields' => [
                [
                    'key' => 'commerce.default_currency_code',
                    'label' => 'Default currency',
                    'type' => 'text',
                    'scope' => 'company',
                    'default' => 'MYR',
                    'placeholder' => 'USD',
                    'help' => 'Used when creating new Commerce records. Existing item currency snapshots are not rewritten.',
                    'rules' => ['required', 'string', 'size:3'],
                    'normalize' => 'uppercase',
                ],
            ],
        ],
    ],
];
