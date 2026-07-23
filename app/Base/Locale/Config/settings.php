<?php

use App\Base\Locale\Services\LocaleSettings;

return [
    'definitions' => [
        LocaleSettings::LOCALE_KEY => [
            'type' => 'string',
            'scopes' => ['user', 'global'],
            'default' => 'en-MY',
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'string', 'max:16'],
            'label' => 'Locale',
            'help' => 'Language and regional formatting used for this account or installation.',
            'editable' => 'admin.system.localization',
            'capability' => 'admin.system.localization.manage',
        ],
    ],

    // Inference provenance written by ApplicationLocaleContext.
    'runtime' => [
        'ui.locale_source',
        'ui.locale_confirmed_at',
        'ui.locale_inferred_country',
    ],
];
